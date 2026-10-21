<?php

namespace App\Services\Guichet;

use App\Enums\StatutPaiement;
use App\Models\ClotureCaisse;
use App\Models\Paiement;
use App\Models\User;
use App\Services\Audit\Journal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Caisse de l'agent de guichet : ses encaissements confirmés, regroupés par jour, non encore clôturés. La clôture d'un
 * jour (une seule par agent et par jour) fige le nombre d'encaissements et le total ; plus aucun encaissement n'est
 * possible ce jour-là.
 */
class CaisseGuichet
{
    public function __construct(private readonly Journal $journal) {}

    /** @return Collection<string, Collection<int, Paiement>> encaissements non clôturés, par jour (« Y-m-d »), le plus récent d'abord */
    public function ouverts(User $agent): Collection
    {
        return Paiement::query()->where('agent_id', $agent->id)->where('statut', StatutPaiement::Confirme)->whereNull('cloture_caisse_id')
            ->with('souscription.user')->orderBy('date_confirmation')->get()
            ->groupBy(fn (Paiement $p) => $p->date_confirmation->toDateString())->sortKeysDesc();
    }

    /** @return Collection<int, ClotureCaisse> clôtures de l'agent pour la journée */
    public function clotures(User $agent, CarbonImmutable $jour): Collection
    {
        return ClotureCaisse::query()->where('agent_id', $agent->id)->whereDate('date_caisse', $jour->toDateString())->get();
    }

    public function estCloturee(User $agent, ?CarbonImmutable $jour = null): bool
    {
        return $this->clotures($agent, $jour ?? CarbonImmutable::today())->isNotEmpty();
    }

    /** Total encaissé (montant et frais) d'une liste de paiements. */
    public static function total(Collection $paiements): int
    {
        return (int) $paiements->sum(fn (Paiement $p) => $p->montant + $p->frais);
    }

    public function cloturer(User $agent, string $jour): ClotureCaisse
    {
        return DB::transaction(function () use ($agent, $jour) {
            // Verrou sur le compte de l'agent : deux clôtures simultanées du même jour sont impossibles.
            User::query()->whereKey($agent->id)->lockForUpdate()->first();
            if ($this->estCloturee($agent, CarbonImmutable::parse($jour))) {
                throw ValidationException::withMessages(['jour' => __('admin.guichet.caisse.deja_cloturee')]);
            }
            $paiements = Paiement::query()->where('agent_id', $agent->id)->where('statut', StatutPaiement::Confirme)->whereNull('cloture_caisse_id')
                ->whereDate('date_confirmation', $jour)->lockForUpdate()->get();
            if ($paiements->isEmpty()) {
                throw ValidationException::withMessages(['jour' => __('admin.guichet.caisse.rien_a_cloturer')]);
            }

            $cloture = ClotureCaisse::create([
                'agent_id' => $agent->id, 'date_caisse' => $jour, 'nb_encaissements' => $paiements->count(),
                'total_montant' => self::total($paiements), 'cloturee_le' => now(),
            ]);
            Paiement::query()->whereIn('id', $paiements->pluck('id'))->update(['cloture_caisse_id' => $cloture->id]);
            $this->journal->ecrire($agent, 'caisse.cloturee', 'ClotureCaisse', $cloture->id, null,
                ['jour' => $jour, 'encaissements' => $cloture->nb_encaissements, 'total' => $cloture->total_montant]);

            return $cloture;
        });
    }
}
