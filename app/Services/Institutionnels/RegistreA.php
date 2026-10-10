<?php

namespace App\Services\Institutionnels;

use App\Enums\ModeInscriptionA;
use App\Enums\StatutConvention;
use App\Models\AppelFonds;
use App\Models\Convention;
use App\Models\LigneRegistreA;
use App\Models\User;
use App\Services\Audit\Journal;
use App\Services\Documents\GenerateurPdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Registre des actions A : inscriptions au fur et à mesure des fonds reçus (au prorata) ou en
 * totalité au dernier versement, selon la convention. Chaque inscription : plage de numéros,
 * certificat, attestation d'actionnaire PDF, chaînage des empreintes, journal d'audit.
 * Le registre des actions B (citoyens) est une autre table : les deux ne se mélangent jamais.
 */
class RegistreA
{
    public const DISQUE = 'local';

    public function __construct(
        private readonly Journal $journal,
        private readonly GenerateurPdf $pdf,
    ) {}

    /** Nombre d'actions A dues à date, d'après les fonds reçus et le mode d'inscription. */
    public static function actionsDues(Convention $convention, int $montantRecu): int
    {
        if ($montantRecu >= $convention->montant_engage) {
            return $convention->nb_actions;
        }

        return $convention->mode_inscription === ModeInscriptionA::Prorata
            ? intdiv($convention->nb_actions * $montantRecu, max(1, $convention->montant_engage))
            : 0;
    }

    public function inscrireSelonConvention(User $acteur, Convention $convention, ?AppelFonds $appel): ?LigneRegistreA
    {
        $aInscrire = self::actionsDues($convention, $convention->montantRecu()) - $convention->actionsInscrites();

        return $aInscrire > 0 ? $this->inscrire($acteur, $convention, $appel, $aInscrire) : null;
    }

    private function inscrire(User $acteur, Convention $convention, ?AppelFonds $appel, int $nbActions): LigneRegistreA
    {
        return DB::transaction(function () use ($acteur, $convention, $appel, $nbActions) {
            // Verrou sur la dernière ligne : numérotation et chaînage sans trou ni doublon.
            $derniere = LigneRegistreA::query()->orderByDesc('id')->lockForUpdate()->first();
            $debut = ($derniere?->numero_fin ?? 0) + 1;
            $numeroCertificat = sprintf('A-%s-%06d', now()->format('Y'), ($derniere?->id ?? 0) + 1);

            $donnees = [
                'numero_certificat' => $numeroCertificat,
                'institution_id' => $convention->institution_id,
                'convention_id' => $convention->id,
                'appel_fonds_id' => $appel?->id,
                'nb_actions' => $nbActions,
                'numero_debut' => $debut,
                'numero_fin' => $debut + $nbActions - 1,
                'date_inscription' => now()->toDateString(),
            ];

            $attestation = $this->pdf->rendre('documents.attestation-actions-a', [
                ...$donnees,
                'institution' => $convention->institution,
                'convention' => $convention,
                'totalDetenu' => (int) LigneRegistreA::query()->where('institution_id', $convention->institution_id)->sum('nb_actions') + $nbActions,
            ]);
            $chemin = 'registre-a/'.$numeroCertificat.'.pdf';
            Storage::disk(self::DISQUE)->put($chemin, $attestation);

            $donnees['attestation_chemin'] = $chemin;
            $donnees['attestation_empreinte'] = hash('sha256', $attestation);
            $donnees['empreinte_precedente'] = $derniere?->empreinte;
            $donnees['empreinte'] = LigneRegistreA::calculerEmpreinte($donnees, $derniere?->empreinte);

            $ligne = LigneRegistreA::create([...$donnees, 'inscrit_par' => $acteur->id, 'created_at' => now()]);

            $this->journal->ecrire($acteur, 'registre_a.inscription', 'LigneRegistreA', $ligne->id, null, [
                'certificat' => $numeroCertificat, 'institution' => $convention->institution_id, 'nb_actions' => $nbActions,
                'plage' => $ligne->numero_debut.'–'.$ligne->numero_fin, 'empreinte' => $ligne->empreinte,
            ]);

            return $ligne;
        });
    }

    /**
     * Vérifie la chaîne du registre A : chaque ligne scelle la précédente.
     */
    public static function chaineIntegre(): bool
    {
        $precedente = null;
        foreach (LigneRegistreA::query()->orderBy('id')->cursor() as $ligne) {
            $donnees = [...$ligne->getAttributes(), 'date_inscription' => $ligne->date_inscription->toDateString()];
            if ($ligne->empreinte_precedente !== $precedente || $ligne->empreinte !== LigneRegistreA::calculerEmpreinte($donnees, $precedente)) {
                return false;
            }
            $precedente = $ligne->empreinte;
        }

        return true;
    }

    /**
     * Objectif des actions A : lignes grises (financements institutionnels) du plan de financement.
     */
    public static function objectifEngagements(): int
    {
        return (int) collect(plateforme('projet.repartitionFinancement'))->where('couleur', 'gris')->sum('montant');
    }

    /** Montants engagés par les conventions confirmées. */
    public static function totalEngage(): int
    {
        return (int) Convention::query()->where('statut', StatutConvention::Confirmee)->sum('montant_engage');
    }

    /**
     * Répartition du capital entre actions A (registre A) et actions B (registre des citoyens).
     *
     * @return array{a: int, b: int, total: int, pct_a: float, pct_b: float}
     */
    public static function repartitionCapital(): array
    {
        $a = (int) LigneRegistreA::query()->sum('nb_actions');
        $b = (int) DB::table('lignes_registre')->sum('nb_actions');
        $total = $a + $b;

        return [
            'a' => $a,
            'b' => $b,
            'total' => $total,
            'pct_a' => $total > 0 ? round($a * 100 / $total, 2) : 0.0,
            'pct_b' => $total > 0 ? round($b * 100 / $total, 2) : 0.0,
        ];
    }
}
