<?php

namespace App\Services\Souscriptions;

use App\Enums\StatutAvenant;
use App\Enums\StatutTranche;
use App\Models\Tranche;
use App\Services\Audit\Journal;
use Illuminate\Support\Facades\DB;

/**
 * Ouverture et clôture automatiques des tranches : une tranche confirmée s'ouvre seule à sa date d'ouverture, une
 * tranche ouverte se clôture seule à sa date de clôture (éventuellement prolongée par avenant). Lancé chaque minute
 * par le planificateur, et à chaque lecture de la tranche ouverte (le résultat ne dépend donc pas du planificateur).
 */
class EcheancesTranches
{
    public function __construct(private readonly Journal $journal) {}

    /** @return array{ouvertes: int, cloturees: int} */
    public function appliquer(): array
    {
        $bilan = ['ouvertes' => 0, 'cloturees' => 0];
        $dues = Tranche::query()
            ->where(fn ($q) => $q->where('statut', StatutTranche::Ouverte)->where('date_cloture', '<=', now()))
            ->orWhere(fn ($q) => $q->where('statut', StatutTranche::Confirmee)->where('date_ouverture', '<=', now()))
            ->orderBy('date_ouverture')->pluck('id');

        foreach ($dues as $id) {
            DB::transaction(function () use ($id, &$bilan) {
                $tranche = Tranche::query()->lockForUpdate()->find($id);
                if (! $tranche) {
                    return;
                }
                $echue = now()->gte($tranche->date_cloture);
                if (in_array($tranche->statut, [StatutTranche::Ouverte, StatutTranche::Confirmee], true) && $echue) {
                    // Ouverte arrivée à sa clôture, ou confirmée dont la période est passée sans ouverture possible.
                    $this->changer($tranche, StatutTranche::Cloturee);
                    $bilan['cloturees']++;
                } elseif ($tranche->statut === StatutTranche::Confirmee && $this->blocage($tranche) === null) {
                    $this->changer($tranche, StatutTranche::Ouverte);
                    $bilan['ouvertes']++;
                }
            });
        }

        return $bilan;
    }

    /** Raison qui empêche l'ouverture automatique de la tranche, ou null si rien ne l'empêche. */
    public function blocage(Tranche $tranche): ?string
    {
        if ($tranche->avenants()->where('statut', StatutAvenant::EnAttente)->exists()) {
            return __('admin.parametrage.regles.avenant_en_cours');
        }
        if (Tranche::query()->where('statut', StatutTranche::Ouverte)->whereKeyNot($tranche->id)->exists()) {
            return __('admin.tranches.regles.une_seule_ouverte');
        }

        return null;
    }

    private function changer(Tranche $tranche, StatutTranche $statut): void
    {
        $avant = $tranche->statut->value;
        $ouverture = $statut === StatutTranche::Ouverte;
        $tranche->forceFill(['statut' => $statut, $ouverture ? 'ouverte_le' : 'cloturee_le' => now()])->save();
        $this->journal->ecrire(null, $ouverture ? 'tranche.ouverte' : 'tranche.cloturee', 'Tranche', $tranche->id, ['statut' => $avant], ['statut' => $statut->value, 'automatique' => true]);
    }
}
