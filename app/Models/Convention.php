<?php

namespace App\Models;

use App\Contracts\Parametrable;
use App\Enums\ModeInscriptionA;
use App\Enums\ModeSignature;
use App\Enums\StatutConvention;
use App\Services\Institutionnels\AppelsDeFonds;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Convention de souscription d'actions A d'une institution : saisie par le RÉGULATEUR, confirmée
 * par le VALIDATEUR (workflow commun), puis signée par le signataire de l'institution.
 * Échéancier : [['libelle', 'echeance' (Y-m-d), 'type' (montant|pourcentage), 'valeur', 'montant'], …].
 */
#[Fillable([
    'institution_id', 'reference', 'statut', 'nb_actions', 'prix_unitaire', 'montant_engage', 'echeancier',
    'droits_particuliers', 'mode_inscription', 'pdf_chemin', 'pdf_nom_original', 'pdf_empreinte', 'version', 'cree_par',
])]
#[Hidden(['pdf_chemin', 'signature_pdf_chemin'])]
class Convention extends Model implements Parametrable
{
    /** Paramètres versionnés (le PDF l'est par son empreinte). */
    public const PARAMETRES = [
        'reference', 'nb_actions', 'prix_unitaire', 'montant_engage', 'echeancier', 'droits_particuliers',
        'mode_inscription', 'pdf_empreinte',
    ];

    protected function casts(): array
    {
        return [
            'statut' => StatutConvention::class,
            'nb_actions' => 'integer',
            'prix_unitaire' => 'integer',
            'montant_engage' => 'integer',
            'echeancier' => 'array',
            'mode_inscription' => ModeInscriptionA::class,
            'version' => 'integer',
            'soumise_le' => 'datetime',
            'confirmee_le' => 'datetime',
            'signature_mode' => ModeSignature::class,
            'signee_le' => 'datetime',
            'signature_deposee_le' => 'datetime',
        ];
    }

    public function typeParametrage(): string
    {
        return 'convention';
    }

    public function snapshotParametres(): array
    {
        $snapshot = [];
        foreach (self::PARAMETRES as $champ) {
            $valeur = $this->getAttribute($champ);
            $snapshot[$champ] = $valeur instanceof \BackedEnum ? $valeur->value : $valeur;
        }

        return $snapshot;
    }

    public function estModifiable(): bool
    {
        return $this->statut === StatutConvention::Brouillon;
    }

    public function estSoumis(): bool
    {
        return $this->statut === StatutConvention::Soumise;
    }

    public function estConfirmee(): bool
    {
        return $this->statut === StatutConvention::Confirmee;
    }

    public function estSignee(): bool
    {
        return $this->signee_le !== null;
    }

    /** Convention signée déposée en PDF, en attente de l'attestation de réception du VALIDATEUR. */
    public function signatureEnAttente(): bool
    {
        return ! $this->estSignee() && $this->signature_mode === ModeSignature::Depot && $this->signature_deposee_le !== null;
    }

    public function marquerSoumis(User $auteur): void
    {
        $this->forceFill(['statut' => StatutConvention::Soumise, 'soumis_par' => $auteur->id, 'soumise_le' => now(), 'motif_rejet' => null])->save();
    }

    /**
     * Confirmée : l'échéancier devient la liste des appels de fonds de l'institution.
     */
    public function marquerConfirme(User $validateur): void
    {
        $this->forceFill(['statut' => StatutConvention::Confirmee, 'confirme_par' => $validateur->id, 'confirmee_le' => now()])->save();
        app(AppelsDeFonds::class)->synchroniser($this);
    }

    public function marquerRejete(string $motif): void
    {
        $this->forceFill(['statut' => StatutConvention::Brouillon, 'motif_rejet' => $motif])->save();
    }

    /**
     * Confirmée : tous les paramètres sont modifiables par avenant tant qu'aucun virement n'a été
     * déclaré ; ensuite seuls les droits particuliers le restent. Une signature électronique déjà
     * apposée devra être renouvelée sur la nouvelle version.
     *
     * @return list<string>|null
     */
    public function champsAvenantAutorises(): ?array
    {
        if (! $this->estConfirmee()) {
            return [];
        }

        return $this->aDesVirements() ? ['droits_particuliers'] : null;
    }

    public function aDesVirements(): bool
    {
        return DeclarationVirement::query()->whereIn('appel_fonds_id', $this->appels()->select('id'))->exists();
    }

    public function appliquerAvenant(array $valeurs): void
    {
        $this->fill(array_intersect_key($valeurs, array_flip(self::PARAMETRES)))->save();

        // Une signature porte sur une version précise : elle tombe si les paramètres changent.
        $this->forceFill(['signature_mode' => null, 'signee_par' => null, 'signee_le' => null, 'signature_empreinte' => null,
            'signature_pdf_chemin' => null, 'signature_pdf_empreinte' => null, 'signature_deposee_le' => null, 'reception_attestee_par' => null])->save();

        app(AppelsDeFonds::class)->synchroniser($this);
    }

    public function creerVersion(User $auteur, ?string $motif = null): ConventionVersion
    {
        $numero = (int) $this->versions()->max('numero') + 1;

        $version = ConventionVersion::create([
            'convention_id' => $this->id,
            'numero' => $numero,
            'parametres' => $this->snapshotParametres(),
            'auteur_id' => $auteur->id,
            'motif' => $motif,
            'created_at' => now(),
        ]);

        $this->forceFill(['version' => $numero])->save();

        return $version;
    }

    /** Montant des actions déjà inscrites au registre A pour cette convention. */
    public function actionsInscrites(): int
    {
        return (int) LigneRegistreA::query()->where('convention_id', $this->id)->sum('nb_actions');
    }

    public function montantRecu(): int
    {
        return (int) $this->appels()->sum('montant_recu');
    }

    /**
     * @return HasMany<ConventionVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(ConventionVersion::class)->orderBy('numero');
    }

    public function avenants(): MorphMany
    {
        return $this->morphMany(Avenant::class, 'avenantable')->latest('id');
    }

    /**
     * @return BelongsTo<Institution, $this>
     */
    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    /**
     * @return HasMany<AppelFonds, $this>
     */
    public function appels(): HasMany
    {
        return $this->hasMany(AppelFonds::class)->orderBy('numero');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function signataire(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signee_par');
    }
}
