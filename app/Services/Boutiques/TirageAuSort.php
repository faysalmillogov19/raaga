<?php

namespace App\Services\Boutiques;

use App\Enums\StatutCampagneBoutiques;
use App\Enums\StatutCandidatureBoutique;
use App\Models\CampagneBoutiques;
use App\Models\CandidatureBoutique;
use App\Models\TirageBoutiques;
use App\Models\User;
use App\Services\Audit\Journal;
use App\Services\Backoffice\RegleGestionException;
use App\Services\Paiement\ServicePaiement;
use App\Services\Sms\EnvoiSms;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tirage au sort des boutiques, catégorie par catégorie, parmi les candidatures dont la caution est payée.
 *
 * Vérifiable : une graine aléatoire est tirée puis publiée ; l'ordre est celui des empreintes
 * SHA-256(graine « | » référence) croissantes. Quiconque dispose de la graine et des références
 * retrouve le même ordre. Les N premiers (N = nombre de boutiques) sont retenus.
 */
class TirageAuSort
{
    public function __construct(
        private readonly Journal $journal,
        private readonly ServicePaiement $paiements,
        private readonly EnvoiSms $sms,
    ) {}

    /** Ordre du tirage pour une graine et des références : fonction pure, rejouable. */
    public static function ordonner(string $graine, array $references): array
    {
        $ordre = array_values($references);
        usort($ordre, fn (string $a, string $b) => strcmp(hash('sha256', $graine.'|'.$a), hash('sha256', $graine.'|'.$b)));

        return $ordre;
    }

    /** Empreinte de la liste des participants (triée), figée avant le tirage. */
    public static function empreinte(array $references): string
    {
        sort($references);

        return hash('sha256', implode("\n", $references));
    }

    /** @throws RegleGestionException */
    public function tirer(CampagneBoutiques $campagne, User $validateur): void
    {
        // Les cautions arrivées à échéance sans paiement ne participent pas.
        $this->paiements->expirerReservations();

        $resultats = DB::transaction(function () use ($campagne, $validateur) {
            $campagne = CampagneBoutiques::query()->lockForUpdate()->findOrFail($campagne->id);

            if ($campagne->statut !== StatutCampagneBoutiques::Cloturee) {
                throw new RegleGestionException(__('admin.boutiques.regles.tirage_cloturee'));
            }
            if (CandidatureBoutique::query()->where('campagne_boutiques_id', $campagne->id)->where('statut', StatutCandidatureBoutique::Reservee)->exists()) {
                throw new RegleGestionException(__('admin.boutiques.regles.cautions_en_attente'));
            }

            $resultats = collect();
            foreach ($campagne->categories as $categorie) {
                $candidatures = CandidatureBoutique::query()->where('categorie_boutique_id', $categorie->id)
                    ->where('statut', StatutCandidatureBoutique::Payee)->lockForUpdate()->get()->keyBy('reference');
                $references = $candidatures->keys()->all();
                $graine = bin2hex(random_bytes(32));
                $ordre = self::ordonner($graine, $references);

                TirageBoutiques::create([
                    'campagne_boutiques_id' => $campagne->id, 'categorie_boutique_id' => $categorie->id,
                    'graine' => $graine, 'empreinte_participants' => self::empreinte($references),
                    'nombre_boutiques' => $categorie->nombre_boutiques, 'nombre_participants' => count($references),
                    'ordre' => $ordre, 'tire_par' => $validateur->id, 'created_at' => now(),
                ]);

                foreach ($ordre as $i => $reference) {
                    $candidatures[$reference]->forceFill([
                        'statut' => $i < $categorie->nombre_boutiques ? StatutCandidatureBoutique::Retenue : StatutCandidatureBoutique::NonRetenue,
                        'rang_tirage' => $i + 1,
                        'tiree_le' => now(),
                    ])->save();
                }
                $resultats = $resultats->merge($candidatures->values());
            }

            $campagne->forceFill(['statut' => StatutCampagneBoutiques::Attribuee, 'attribuee_le' => now()])->save();
            $this->journal->ecrire($validateur, 'campagne_boutiques.tiree', 'CampagneBoutiques', $campagne->id, ['statut' => 'CLOTUREE'], [
                'statut' => 'ATTRIBUEE', 'participants' => $resultats->count(),
                'retenus' => $resultats->where('statut', StatutCandidatureBoutique::Retenue)->count(),
            ]);

            return $resultats;
        });

        $this->prevenir($resultats);
    }

    /** SMS du résultat à chaque participant ; un échec d'envoi n'annule pas le tirage. */
    private function prevenir(Collection $candidatures): void
    {
        foreach ($candidatures as $candidature) {
            try {
                $this->sms->envoyer($candidature->user->telephone, __('public.boutiques.sms.'.($candidature->statut === StatutCandidatureBoutique::Retenue ? 'retenue' : 'non_retenue')), [
                    'reference' => $candidature->reference,
                    'categorie' => $candidature->categorie->libelle,
                ]);
            } catch (Throwable $e) {
                Log::warning('SMS de résultat du tirage non envoyé', ['candidature' => $candidature->id, 'erreur' => $e->getMessage()]);
            }
        }
    }
}
