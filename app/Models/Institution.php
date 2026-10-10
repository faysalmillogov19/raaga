<?php

namespace App\Models;

use App\Enums\Role as RoleEnum;
use App\Enums\StatutDocumentKyc;
use App\Enums\StatutInstitution;
use App\Enums\TypeDocumentKyb;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Investisseur institutionnel (caisse de sécurité sociale, caisse de retraite, investisseur stratégique) détenteur d'actions A.
 * Cycle : INVITÉE → DOSSIER_EN_COURS → VÉRIFIÉE → ACTIVE (convention signée), ou SUSPENDUE.
 */
#[Fillable([
    'denomination', 'sigle', 'forme_juridique', 'rccm', 'ifu', 'adresse', 'secteur', 'statut', 'motif_suspension',
    'rib_titulaire', 'rib_banque', 'rib_numero', 'interlocuteur_nom', 'interlocuteur_email', 'interlocuteur_telephone',
    'cree_par', 'verifiee_le',
])]
class Institution extends Model
{
    protected function casts(): array
    {
        return [
            'statut' => StatutInstitution::class,
            'verifiee_le' => 'datetime',
        ];
    }

    public function nomCourt(): string
    {
        return $this->sigle ?: $this->denomination;
    }

    public function estSuspendue(): bool
    {
        return $this->statut === StatutInstitution::Suspendue;
    }

    /** Dossier KYB vérifié (condition de la signature d'une convention). */
    public function estVerifiee(): bool
    {
        return in_array($this->statut, [StatutInstitution::Verifiee, StatutInstitution::Active], true);
    }

    /**
     * @return HasMany<User, $this>
     */
    public function utilisateurs(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return Builder<User>
     */
    public function signatairesActifs(): Builder
    {
        return User::query()->where('institution_id', $this->id)->where('actif', true)
            ->whereHas('role', fn (Builder $q) => $q->where('code', RoleEnum::InstitutionSignataire->value));
    }

    /**
     * @return HasMany<InstitutionDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(InstitutionDocument::class)->latest('id');
    }

    /**
     * Pièce en vigueur pour chaque type (la plus récente déposée), indexée par type.
     *
     * @return Collection<string, InstitutionDocument>
     */
    public function piecesEnVigueur(): Collection
    {
        return $this->documents()->get()->unique(fn (InstitutionDocument $d) => $d->type->value)->keyBy(fn ($d) => $d->type->value);
    }

    /** Toutes les pièces exigées sont déposées et validées. */
    public function dossierComplet(): bool
    {
        $pieces = $this->piecesEnVigueur();

        return collect(TypeDocumentKyb::cases())->every(fn (TypeDocumentKyb $t) => $pieces->get($t->value)?->statut === StatutDocumentKyc::Valide);
    }

    /**
     * @return HasMany<Convention, $this>
     */
    public function conventions(): HasMany
    {
        return $this->hasMany(Convention::class)->latest('id');
    }

    /**
     * @return HasMany<LigneRegistreA, $this>
     */
    public function lignesRegistre(): HasMany
    {
        return $this->hasMany(LigneRegistreA::class)->orderBy('id');
    }
}
