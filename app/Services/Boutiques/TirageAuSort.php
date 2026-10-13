<?php

namespace App\Services\Boutiques;

use App\Enums\StatutBoutique;
use App\Enums\StatutCampagneBoutiques;
use App\Enums\StatutCandidatureBoutique;
use App\Models\AffectationBoutiques;
use App\Models\Boutique;
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
 * Attribution des boutiques en deux tirages au sort vérifiables (graines publiées, ordres = empreintes
 * SHA-256 croissantes de « graine|identifiant ») :
 *
 * 1. tirer() : dans chaque lot (site × type), parmi les cautions payées, les candidats sont classés ; les premiers
 *    sont retenus dans la limite du nombre de boutiques. Le plafond de boutiques par personne s'applique dans
 *    l'ordre des lots : une candidature dont le titulaire a atteint le plafond est écartée et la suivante prend sa place.
 * 2. affecter() : dans chaque lot, les boutiques sont classées par un second tirage ; le 1er retenu reçoit la
 *    1re boutique, et ainsi de suite. Les boutiques restantes redeviennent disponibles.
 */
class TirageAuSort
{
    public function __construct(
        private readonly Journal $journal,
        private readonly ServicePaiement $paiements,
        private readonly EnvoiSms $sms,
    ) {}

    /** Ordre du tirage pour une graine et des identifiants : fonction pure, rejouable. */
    public static function ordonner(string $graine, array $identifiants): array
    {
        $ordre = array_values($identifiants);
        usort($ordre, fn (string $a, string $b) => strcmp(hash('sha256', $graine.'|'.$a), hash('sha256', $graine.'|'.$b)));

        return $ordre;
    }

    /** Empreinte de la liste des participants (triée), figée avant le tirage. */
    public static function empreinte(array $references): string
    {
        sort($references);

        return hash('sha256', implode("\n", $references));
    }

    /** Premier tirage : candidats retenus par lot. @throws RegleGestionException */
    public function tirer(CampagneBoutiques $campagne, User $validateur): void
    {
        // Les cautions arrivées à échéance sans paiement ne participent pas.
        $this->paiements->expirerReservations();

        $candidatures = DB::transaction(function () use ($campagne, $validateur) {
            $campagne = CampagneBoutiques::query()->lockForUpdate()->findOrFail($campagne->id);

            if ($campagne->statut !== StatutCampagneBoutiques::Cloturee) {
                throw new RegleGestionException(__('admin.boutiques.regles.tirage_cloturee'));
            }
            if (CandidatureBoutique::query()->where('campagne_boutiques_id', $campagne->id)->where('statut', StatutCandidatureBoutique::Reservee)->exists()) {
                throw new RegleGestionException(__('admin.boutiques.regles.cautions_en_attente'));
            }

            $plafond = $campagne->max_boutiques_par_personne;
            $obtenues = [];
            $toutes = collect();

            foreach ($campagne->categories as $lot) {
                $candidatures = CandidatureBoutique::query()->where('categorie_boutique_id', $lot->id)
                    ->where('statut', StatutCandidatureBoutique::Payee)->lockForUpdate()->get()->keyBy('reference');
                $references = $candidatures->keys()->all();
                $graine = bin2hex(random_bytes(32));
                $ordre = self::ordonner($graine, $references);

                $retenus = [];
                $ecartes = [];
                foreach ($ordre as $i => $reference) {
                    $candidature = $candidatures[$reference];
                    $place = count($retenus) < $lot->nombre_boutiques;
                    $auPlafond = $plafond !== null && ($obtenues[$candidature->user_id] ?? 0) >= $plafond;

                    if ($place && $auPlafond) {
                        $ecartes[] = $reference;
                    } elseif ($place) {
                        $retenus[] = $reference;
                        $obtenues[$candidature->user_id] = ($obtenues[$candidature->user_id] ?? 0) + 1;
                    }

                    $candidature->forceFill([
                        'statut' => in_array($reference, $retenus, true) ? StatutCandidatureBoutique::Retenue : StatutCandidatureBoutique::NonRetenue,
                        'rang_tirage' => $i + 1,
                        'tiree_le' => now(),
                    ])->save();
                }

                TirageBoutiques::create([
                    'campagne_boutiques_id' => $campagne->id, 'categorie_boutique_id' => $lot->id,
                    'graine' => $graine, 'empreinte_participants' => self::empreinte($references),
                    'nombre_boutiques' => $lot->nombre_boutiques, 'nombre_participants' => count($references),
                    'ordre' => $ordre, 'retenus' => $retenus, 'ecartes' => $ecartes, 'tire_par' => $validateur->id, 'created_at' => now(),
                ]);
                $toutes = $toutes->merge($candidatures->values());
            }

            $campagne->forceFill(['statut' => StatutCampagneBoutiques::Tiree])->save();
            $this->journal->ecrire($validateur, 'campagne_boutiques.tiree', 'CampagneBoutiques', $campagne->id, ['statut' => 'CLOTUREE'], [
                'statut' => 'TIREE', 'participants' => $toutes->count(),
                'retenus' => $toutes->where('statut', StatutCandidatureBoutique::Retenue)->count(),
            ]);

            return $toutes;
        });

        // Les non-retenus sont prévenus tout de suite ; les retenus le seront avec le numéro de leur boutique.
        $this->prevenir($candidatures->where('statut', StatutCandidatureBoutique::NonRetenue), 'non_retenue');
    }

    /** Second tirage : boutique précise de chaque retenu. @throws RegleGestionException */
    public function affecter(CampagneBoutiques $campagne, User $validateur): void
    {
        $retenus = DB::transaction(function () use ($campagne, $validateur) {
            $campagne = CampagneBoutiques::query()->lockForUpdate()->findOrFail($campagne->id);

            if ($campagne->statut !== StatutCampagneBoutiques::Tiree) {
                throw new RegleGestionException(__('admin.boutiques.regles.affectation_tiree'));
            }

            $retenus = collect();
            $tirages = TirageBoutiques::query()->where('campagne_boutiques_id', $campagne->id)->with('categorie')->get();

            foreach ($tirages as $tirage) {
                $lot = $tirage->categorie;
                $boutiques = $lot->site_id
                    ? $lot->requeteBoutiques()->where('statut', StatutBoutique::EnCampagne)->lockForUpdate()->get()
                    : collect();
                $graine = bin2hex(random_bytes(32));
                $ordreBoutiques = self::ordonner($graine, $boutiques->map(fn (Boutique $b) => $b->reference())->all());

                $affectations = [];
                $attribuees = [];
                foreach ((array) $tirage->retenus as $i => $reference) {
                    $candidature = CandidatureBoutique::query()->where('reference', $reference)->lockForUpdate()->firstOrFail();
                    $numero = $ordreBoutiques[$i] ?? null;
                    if ($numero === null) {
                        continue; // Lot sans référentiel (ancienne campagne) : pas de boutique précise.
                    }
                    $boutique = $boutiques->first(fn (Boutique $b) => $b->reference() === $numero);
                    $boutique->forceFill(['statut' => StatutBoutique::Attribuee])->save();
                    $attribuees[] = $boutique->id;
                    $candidature->forceFill(['boutique_id' => $boutique->id])->save();
                    $affectations[$reference] = $numero;
                    $retenus->push($candidature->setRelation('boutique', $boutique));
                }

                // Boutiques du lot non attribuées (moins de retenus que de boutiques) : de nouveau disponibles.
                Boutique::query()->whereIn('id', $boutiques->pluck('id')->diff($attribuees)->values())->update(['statut' => StatutBoutique::Disponible]);

                AffectationBoutiques::create([
                    'tirage_boutiques_id' => $tirage->id, 'graine' => $graine, 'ordre_boutiques' => $ordreBoutiques,
                    'affectations' => $affectations, 'tire_par' => $validateur->id, 'created_at' => now(),
                ]);
            }

            $campagne->forceFill(['statut' => StatutCampagneBoutiques::Attribuee, 'attribuee_le' => now()])->save();
            $this->journal->ecrire($validateur, 'campagne_boutiques.affectee', 'CampagneBoutiques', $campagne->id, ['statut' => 'TIREE'], [
                'statut' => 'ATTRIBUEE', 'boutiques_attribuees' => $retenus->count(),
            ]);

            return $retenus;
        });

        $this->prevenir($retenus, 'retenue');
    }

    /** SMS du résultat ; un échec d'envoi n'annule pas le tirage. */
    private function prevenir(Collection $candidatures, string $modele): void
    {
        foreach ($candidatures as $candidature) {
            try {
                $this->sms->envoyer($candidature->user->telephone, __('public.boutiques.sms.'.$modele), [
                    'reference' => $candidature->reference,
                    'categorie' => $candidature->categorie->intitule(),
                    'boutique' => $candidature->boutique?->reference(),
                ]);
            } catch (Throwable $e) {
                Log::warning('SMS de résultat du tirage non envoyé', ['candidature' => $candidature->id, 'erreur' => $e->getMessage()]);
            }
        }
    }
}
