<?php

namespace Tests\Support;

use App\Enums\StatutGrilleTarifaire;
use App\Models\GrilleTarifaire;
use App\Models\Site;

/** Grille tarifaire saisie par le RÉGULATEUR puis confirmée par le VALIDATEUR (circuit réel, par HTTP). */
trait GrillesTarifaires
{
    /** @param array<string, array<int, array{0: int, 1: int, 2: int}>> $lignes [niveau][type_id] => [loyer_m2, mois_caution, mois_avance] */
    protected function grilleConfirmee(Site $site, array $lignes): GrilleTarifaire
    {
        $saisie = [];
        foreach ($lignes as $niveau => $parType) {
            foreach ($parType as $typeId => [$loyer, $caution, $avance]) {
                $saisie[$niveau][$typeId] = ['loyer_m2' => $loyer, 'mois_caution' => $caution, 'mois_avance' => $avance];
            }
        }
        if ($site->grilleTarifaire()->where('statut', StatutGrilleTarifaire::Confirme)->exists()) {
            $this->en($this->regulateur)->post(route('admin.boutiques.grilles.reviser', $site))->assertSessionHas('succes');
        }
        $this->en($this->regulateur)->put(route('admin.boutiques.grilles.update', $site), ['lignes' => $saisie])->assertSessionHasNoErrors();
        $this->en($this->regulateur)->post(route('admin.boutiques.grilles.soumettre', $site))->assertSessionHas('succes');
        $this->en($this->validateur)->post(route('admin.boutiques.grilles.confirmer', $site))->assertSessionHas('succes');

        return $site->grilleTarifaire()->firstOrFail();
    }
}
