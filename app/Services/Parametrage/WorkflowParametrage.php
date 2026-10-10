<?php

namespace App\Services\Parametrage;

use App\Contracts\Parametrable;
use App\Enums\DecisionParametrage;
use App\Enums\StatutAvenant;
use App\Models\Avenant;
use App\Models\ParametrageDecision;
use App\Models\User;
use App\Services\Audit\Journal;
use App\Services\Backoffice\RegleGestionException;
use Illuminate\Support\Facades\DB;

/**
 * Workflow commun à tout paramétrage (tranche, distribution de dividendes) :
 * BROUILLON → SOUMIS → CONFIRMÉ, ou REJETÉ (retour en brouillon avec le motif).
 *
 * - Chaque soumission fige une version (snapshot, auteur, date).
 * - Quatre yeux : la personne qui confirme ou rejette n'est jamais l'auteur de la version soumise.
 * - Un paramétrage confirmé n'évolue que par avenant, soumis puis confirmé, tracé.
 */
class WorkflowParametrage
{
    public function __construct(private readonly Journal $journal) {}

    public function soumettre(Parametrable $objet, User $auteur): void
    {
        if (! $objet->estModifiable()) {
            throw new RegleGestionException(__('admin.parametrage.regles.non_modifiable'));
        }

        DB::transaction(function () use ($objet, $auteur) {
            $version = $objet->creerVersion($auteur, __('admin.parametrage.motifs.soumission'));
            $objet->marquerSoumis($auteur);
            $this->journal->ecrire($auteur, $objet->typeParametrage().'.soumis', class_basename($objet), $objet->getKey(), null, ['version' => $version->numero]);
        });
    }

    public function confirmer(Parametrable $objet, User $validateur): void
    {
        $this->verifierDecisionPossible($objet, $validateur);

        DB::transaction(function () use ($objet, $validateur) {
            $version = (int) $objet->versions()->max('numero');
            $objet->marquerConfirme($validateur);
            $this->decision($objet, $version, DecisionParametrage::Confirme, null, $validateur);
            $this->journal->ecrire($validateur, $objet->typeParametrage().'.confirme', class_basename($objet), $objet->getKey(), null, ['version' => $version]);
        });
    }

    public function rejeter(Parametrable $objet, User $validateur, string $motif): void
    {
        $this->verifierDecisionPossible($objet, $validateur);

        DB::transaction(function () use ($objet, $validateur, $motif) {
            $version = (int) $objet->versions()->max('numero');
            $objet->marquerRejete($motif);
            $this->decision($objet, $version, DecisionParametrage::Rejete, $motif, $validateur);
            $this->journal->ecrire($validateur, $objet->typeParametrage().'.rejete', class_basename($objet), $objet->getKey(), null, ['version' => $version, 'motif' => $motif]);
        });
    }

    /**
     * Propose un avenant à un paramétrage confirmé (ou à une tranche ouverte : date de clôture seulement).
     *
     * @param  array<string, mixed>  $valeurs  paramètres proposés (validés en amont)
     */
    public function proposerAvenant(Parametrable $objet, User $auteur, array $valeurs, string $motif): Avenant
    {
        $autorises = $objet->champsAvenantAutorises();

        if ($autorises === []) {
            throw new RegleGestionException(__('admin.parametrage.regles.avenant_impossible'));
        }

        if ($objet->avenants()->where('statut', StatutAvenant::EnAttente)->exists()) {
            throw new RegleGestionException(__('admin.parametrage.regles.avenant_en_cours'));
        }

        $actuel = $objet->snapshotParametres();
        $propose = $this->normaliser($objet, $valeurs);
        $champs = array_keys(array_filter($propose, fn ($v, $champ) => array_key_exists($champ, $actuel) && $actuel[$champ] != $v, ARRAY_FILTER_USE_BOTH));

        if ($autorises !== null) {
            $interdits = array_diff($champs, $autorises);
            if ($interdits !== []) {
                throw new RegleGestionException(__('admin.parametrage.regles.avenant_champs', ['champs' => implode(', ', $autorises)]));
            }
        }

        if ($champs === []) {
            throw new RegleGestionException(__('admin.parametrage.regles.avenant_vide'));
        }

        return DB::transaction(function () use ($objet, $auteur, $valeurs, $champs, $actuel, $motif) {
            $avenant = $objet->avenants()->create([
                'ancienne_valeur' => array_intersect_key($actuel, array_flip($champs)),
                'nouvelle_valeur' => array_intersect_key($valeurs, array_flip($champs)),
                'motif' => $motif,
                'statut' => StatutAvenant::EnAttente,
                'saisi_par' => $auteur->id,
            ]);

            $this->journal->ecrire($auteur, $objet->typeParametrage().'.avenant_soumis', class_basename($objet), $objet->getKey(), null, [
                'avenant' => $avenant->id,
                'champs' => $champs,
            ]);

            return $avenant;
        });
    }

    public function confirmerAvenant(Avenant $avenant, User $validateur): void
    {
        $objet = $this->verifierAvenantEnAttente($avenant, $validateur);

        DB::transaction(function () use ($avenant, $objet, $validateur) {
            $objet->appliquerAvenant($avenant->nouvelle_valeur);
            $version = $objet->creerVersion($avenant->auteur, __('admin.parametrage.motifs.avenant', ['n' => $avenant->id]).' — '.$avenant->motif);
            $avenant->forceFill(['statut' => StatutAvenant::Approuve, 'decide_par' => $validateur->id, 'decide_le' => now()])->save();
            $this->decision($objet, $version->numero, DecisionParametrage::Confirme, null, $validateur);
            $this->journal->ecrire($validateur, $objet->typeParametrage().'.avenant_confirme', class_basename($objet), $objet->getKey(),
                $avenant->ancienne_valeur, $avenant->nouvelle_valeur);
        });
    }

    public function rejeterAvenant(Avenant $avenant, User $validateur, string $motif): void
    {
        $objet = $this->verifierAvenantEnAttente($avenant, $validateur);

        DB::transaction(function () use ($avenant, $objet, $validateur, $motif) {
            $avenant->forceFill(['statut' => StatutAvenant::Rejete, 'decide_par' => $validateur->id, 'decide_le' => now(), 'motif_decision' => $motif])->save();
            $this->journal->ecrire($validateur, $objet->typeParametrage().'.avenant_rejete', class_basename($objet), $objet->getKey(), null, [
                'avenant' => $avenant->id,
                'motif' => $motif,
            ]);
        });
    }

    /**
     * Différentiel entre deux jeux de paramètres : [champ => [avant, après]] pour les seuls champs modifiés.
     *
     * @param  array<string, mixed>  $avant
     * @param  array<string, mixed>  $apres
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public static function differentiel(array $avant, array $apres): array
    {
        $diff = [];

        foreach ($apres as $champ => $valeur) {
            if (! array_key_exists($champ, $avant) || $avant[$champ] != $valeur) {
                $diff[$champ] = [$avant[$champ] ?? null, $valeur];
            }
        }

        return $diff;
    }

    private function verifierDecisionPossible(Parametrable $objet, User $validateur): void
    {
        if (! $objet->estSoumis()) {
            throw new RegleGestionException(__('admin.parametrage.regles.non_soumis'));
        }

        $auteur = $objet->versions()->reorder('numero', 'desc')->value('auteur_id');

        if ($auteur === $validateur->id) {
            throw new RegleGestionException(__('admin.parametrage.regles.quatre_yeux'));
        }
    }

    private function verifierAvenantEnAttente(Avenant $avenant, User $validateur): Parametrable
    {
        if ($avenant->statut !== StatutAvenant::EnAttente) {
            throw new RegleGestionException(__('admin.parametrage.regles.avenant_traite'));
        }

        if ($avenant->saisi_par === $validateur->id) {
            throw new RegleGestionException(__('admin.parametrage.regles.quatre_yeux'));
        }

        /** @var Parametrable $objet */
        $objet = $avenant->avenantable;

        return $objet;
    }

    private function decision(Parametrable $objet, int $version, DecisionParametrage $decision, ?string $motif, User $decideur): void
    {
        ParametrageDecision::create([
            'objet_type' => $objet->typeParametrage(),
            'objet_id' => $objet->getKey(),
            'version' => $version,
            'decision' => $decision,
            'motif' => $motif,
            'decideur_id' => $decideur->id,
            'created_at' => now(),
        ]);
    }

    /**
     * Valeurs proposées mises au format du snapshot (enums → valeur, dates → texte), pour comparer.
     *
     * @param  array<string, mixed>  $valeurs
     * @return array<string, mixed>
     */
    private function normaliser(Parametrable $objet, array $valeurs): array
    {
        $copie = $objet->replicate();
        $copie->fill($valeurs);

        return array_intersect_key($copie->snapshotParametres(), $valeurs);
    }
}
