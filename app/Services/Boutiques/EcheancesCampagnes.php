<?php

namespace App\Services\Boutiques;

use App\Enums\StatutAvenant;
use App\Enums\StatutCampagneBoutiques;
use App\Models\CampagneBoutiques;
use App\Models\GrilleTarifaire;
use App\Models\Site;
use App\Services\Audit\Journal;
use Illuminate\Support\Facades\DB;

/**
 * Ouverture et clôture automatiques des campagnes de boutiques : une campagne confirmée s'ouvre seule à sa date
 * d'ouverture, une campagne ouverte se clôture seule à sa date de clôture. Lancé chaque minute par le planificateur,
 * et à chaque consultation des pages concernées (le résultat ne dépend donc pas du planificateur).
 */
class EcheancesCampagnes
{
    public function __construct(private readonly Journal $journal) {}

    /** @return array{ouvertes: int, cloturees: int} */
    public function appliquer(): array
    {
        $bilan = ['ouvertes' => 0, 'cloturees' => 0];
        $dues = CampagneBoutiques::query()
            ->where(fn ($q) => $q->where('statut', StatutCampagneBoutiques::Ouverte)->where('date_cloture', '<=', now()))
            ->orWhere(fn ($q) => $q->where('statut', StatutCampagneBoutiques::Confirmee)->where('date_ouverture', '<=', now()))
            ->orderBy('date_ouverture')->pluck('id');

        foreach ($dues as $id) {
            DB::transaction(function () use ($id, &$bilan) {
                $campagne = CampagneBoutiques::query()->lockForUpdate()->find($id);
                if (! $campagne) {
                    return;
                }
                $echue = now()->gte($campagne->date_cloture);
                if ($campagne->statut === StatutCampagneBoutiques::Ouverte && $echue) {
                    $this->changer($campagne, StatutCampagneBoutiques::Cloturee);
                    $bilan['cloturees']++;
                } elseif ($campagne->statut === StatutCampagneBoutiques::Confirmee && $echue) {
                    // Période passée sans ouverture possible : la campagne est close, sans réservation.
                    $this->changer($campagne, StatutCampagneBoutiques::Cloturee);
                    $bilan['cloturees']++;
                } elseif ($campagne->statut === StatutCampagneBoutiques::Confirmee && $this->blocage($campagne) === null) {
                    $this->changer($campagne, StatutCampagneBoutiques::Ouverte);
                    $bilan['ouvertes']++;
                }
            });
        }

        return $bilan;
    }

    /** Raison qui empêche l'ouverture automatique de la campagne, ou null si rien ne l'empêche. */
    public function blocage(CampagneBoutiques $campagne): ?string
    {
        // Avenant en attente : pas d'ouverture avant la décision du VALIDATEUR.
        if ($campagne->avenants()->where('statut', StatutAvenant::EnAttente)->exists()) {
            return __('admin.parametrage.regles.avenant_en_cours');
        }
        if ($raison = $campagne->raisonPaiementsFermes()) {
            return __('admin.boutiques.regles.paiements_fermes', ['raison' => $raison]);
        }
        if ($sans = self::sitesSansGrille($campagne)) {
            return __('admin.boutiques.regles.sans_grille', ['lots' => implode(', ', $sans)]);
        }
        if (CampagneBoutiques::query()->where('statut', StatutCampagneBoutiques::Ouverte)->whereKeyNot($campagne->id)->exists()) {
            return __('admin.boutiques.regles.une_seule_ouverte');
        }

        return null;
    }

    /** @return list<string> noms des sites de la campagne sans grille tarifaire confirmée */
    public static function sitesSansGrille(CampagneBoutiques $campagne): array
    {
        return $campagne->sitesConcernes()->filter(fn (Site $s) => GrilleTarifaire::query()->where('site_id', $s->id)->first()?->confirmee() === null)
            ->map(fn (Site $s) => $s->nom)->values()->all();
    }

    private function changer(CampagneBoutiques $campagne, StatutCampagneBoutiques $statut): void
    {
        $avant = $campagne->statut->value;
        $date = $statut === StatutCampagneBoutiques::Ouverte ? 'ouverte_le' : 'cloturee_le';
        $campagne->forceFill(['statut' => $statut, $date => now()])->save();
        $action = $statut === StatutCampagneBoutiques::Ouverte ? 'campagne_boutiques.ouverte' : 'campagne_boutiques.cloturee';
        $this->journal->ecrire(null, $action, 'CampagneBoutiques', $campagne->id, ['statut' => $avant], ['statut' => $statut->value, 'automatique' => true]);
    }
}
