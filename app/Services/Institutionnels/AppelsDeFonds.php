<?php

namespace App\Services\Institutionnels;

use App\Enums\StatutAppelFonds;
use App\Enums\StatutDeclarationVirement;
use App\Models\AppelFonds;
use App\Models\Convention;
use App\Models\DeclarationVirement;
use App\Models\User;
use App\Services\Audit\Journal;
use App\Services\Backoffice\RegleGestionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Appels de fonds des conventions d'actions A : créés depuis l'échéancier confirmé, payés par
 * virement (déclaré par le signataire), rapprochés par le VALIDATEUR avec le relevé du compte
 * séquestre. Chaque réception peut inscrire des actions au registre A (selon la convention).
 */
class AppelsDeFonds
{
    public const DISQUE = 'local';

    public function __construct(
        private readonly Journal $journal,
        private readonly RegistreA $registre,
    ) {}

    /**
     * Montants de l'échéancier : une ligne est saisie en montant (FCFA) ou en pourcentage du
     * montant engagé (arrondi au franc inférieur).
     *
     * @param  list<array{libelle: string, echeance: string, type: string, valeur: int|float|string}>  $lignes
     * @return list<array{libelle: string, echeance: string, type: string, valeur: int|float, montant: int}>
     */
    public static function calculerEcheancier(int $montantEngage, array $lignes): array
    {
        return array_values(array_map(fn (array $l) => [
            'libelle' => trim($l['libelle']),
            'echeance' => $l['echeance'],
            'type' => $l['type'],
            'valeur' => $l['type'] === 'pourcentage' ? (float) $l['valeur'] : (int) $l['valeur'],
            'montant' => $l['type'] === 'pourcentage'
                ? intdiv($montantEngage * (int) round((float) $l['valeur'] * 100), 10_000)
                : (int) $l['valeur'],
        ], collect($lignes)->sortBy('echeance')->values()->all()));
    }

    /**
     * (Re)crée les appels de fonds depuis l'échéancier de la convention confirmée. Impossible dès
     * qu'un virement a été déclaré : l'échéancier est alors figé.
     */
    public function synchroniser(Convention $convention): void
    {
        if ($convention->aDesVirements()) {
            return;
        }

        DB::transaction(function () use ($convention) {
            $convention->appels()->delete();

            foreach ($convention->echeancier as $i => $ligne) {
                $numero = $i + 1;
                AppelFonds::create([
                    'convention_id' => $convention->id,
                    'institution_id' => $convention->institution_id,
                    'numero' => $numero,
                    'libelle' => $ligne['libelle'],
                    'echeance' => $ligne['echeance'],
                    'montant' => $ligne['montant'],
                    'montant_recu' => 0,
                    'statut' => StatutAppelFonds::APayer,
                    // Référence obligatoire sur le virement : convention et numéro de l'appel.
                    'reference_virement' => sprintf('AF-%05d-%02d', $convention->id, $numero),
                ]);
            }
        });
    }

    /**
     * @param  array{date_virement: string, montant: int, reference: string}  $donnees
     */
    public function declarer(User $signataire, AppelFonds $appel, array $donnees, ?UploadedFile $avis): DeclarationVirement
    {
        if (! $signataire->estSignataire() || $signataire->institution_id !== $appel->institution_id) {
            abort(403);
        }
        if (! $appel->convention->estSignee()) {
            throw new RegleGestionException(__('institutionnel.regles.convention_non_signee'));
        }
        if ($appel->estSolde()) {
            throw new RegleGestionException(__('institutionnel.regles.appel_solde'));
        }

        return DB::transaction(function () use ($signataire, $appel, $donnees, $avis) {
            $chemin = $avis ? Storage::disk(self::DISQUE)->putFileAs('institutions/'.$appel->institution_id.'/virements', $avis, Str::uuid().'.pdf') : null;

            $declaration = DeclarationVirement::create([
                ...$donnees,
                'appel_fonds_id' => $appel->id,
                'institution_id' => $appel->institution_id,
                'avis_chemin' => $chemin,
                'avis_empreinte' => $avis ? hash_file('sha256', $avis->getRealPath()) : null,
                'declare_par' => $signataire->id,
                'statut' => StatutDeclarationVirement::Declaree,
            ]);

            $this->recalculerStatut($appel);
            $this->journal->ecrire($signataire, 'appel_fonds.virement_declare', 'AppelFonds', $appel->id, null, [
                'montant' => $declaration->montant, 'reference' => $declaration->reference,
            ]);

            return $declaration;
        });
    }

    /**
     * Le VALIDATEUR a retrouvé le virement sur le relevé du compte séquestre : réception confirmée.
     */
    public function rapprocher(User $validateur, DeclarationVirement $declaration, int $montantRecu, string $recuLe, string $referenceReleve): void
    {
        $this->verifierEnAttente($declaration);

        DB::transaction(function () use ($validateur, $declaration, $montantRecu, $recuLe, $referenceReleve) {
            $appel = $declaration->appel()->lockForUpdate()->first();

            $declaration->forceFill([
                'statut' => StatutDeclarationVirement::Rapprochee, 'montant_recu' => $montantRecu, 'recu_le' => $recuLe,
                'reference_releve' => $referenceReleve, 'rapproche_par' => $validateur->id, 'rapproche_le' => now(),
            ])->save();

            $appel->forceFill(['montant_recu' => $appel->montant_recu + $montantRecu])->save();
            $this->recalculerStatut($appel);

            $this->journal->ecrire($validateur, 'appel_fonds.virement_recu', 'AppelFonds', $appel->id, null, [
                'montant_recu' => $montantRecu, 'reference_releve' => $referenceReleve, 'statut' => $appel->statut->value,
            ]);

            // Actions A dues selon la convention (au prorata, ou en totalité au dernier versement).
            $this->registre->inscrireSelonConvention($validateur, $appel->convention, $appel);
        });
    }

    public function rejeter(User $validateur, DeclarationVirement $declaration, string $motif): void
    {
        $this->verifierEnAttente($declaration);

        DB::transaction(function () use ($validateur, $declaration, $motif) {
            $declaration->forceFill([
                'statut' => StatutDeclarationVirement::Rejetee, 'motif_rejet' => $motif,
                'rapproche_par' => $validateur->id, 'rapproche_le' => now(),
            ])->save();

            $this->recalculerStatut($declaration->appel);
            $this->journal->ecrire($validateur, 'appel_fonds.virement_rejete', 'AppelFonds', $declaration->appel_fonds_id, null, ['motif' => $motif]);
        });
    }

    /**
     * Statut d'un appel : REÇU (soldé), PARTIEL, DÉCLARÉ (virement à rapprocher), EN RETARD
     * (échéance passée), sinon À PAYER.
     */
    public function recalculerStatut(AppelFonds $appel): void
    {
        $appel->refresh();
        $declare = $appel->declarations()->where('statut', StatutDeclarationVirement::Declaree)->exists();

        $statut = match (true) {
            $appel->montant_recu >= $appel->montant => StatutAppelFonds::Recu,
            $declare => StatutAppelFonds::Declare,
            $appel->echeance->endOfDay()->isPast() => StatutAppelFonds::EnRetard,
            $appel->montant_recu > 0 => StatutAppelFonds::Partiel,
            default => StatutAppelFonds::APayer,
        };

        $appel->forceFill(['statut' => $statut, 'recu_le' => $statut === StatutAppelFonds::Recu ? ($appel->recu_le ?? now()) : null])->save();
    }

    private function verifierEnAttente(DeclarationVirement $declaration): void
    {
        if ($declaration->statut !== StatutDeclarationVirement::Declaree) {
            throw new RegleGestionException(__('institutionnel.regles.declaration_traitee'));
        }
    }
}
