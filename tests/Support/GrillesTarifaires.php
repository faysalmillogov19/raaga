<?php

namespace Tests\Support;

use App\Enums\StatutGrilleTarifaire;
use App\Models\GrilleTarifaire;
use App\Models\Site;
use App\Models\TypeBoutique;

/** Grille tarifaire saisie par le RÉGULATEUR puis confirmée par le VALIDATEUR (circuit réel, par HTTP). */
trait GrillesTarifaires
{
    /**
     * @param  array<string, array<int, array{0: int, 1: int, 2: int, 3?: string}>>  $lignes  [niveau][type_id] => [loyer, mois_caution, mois_avance, unité]
     *                                                                                        (unité par défaut : celle proposée pour le type)
     */
    protected function grilleConfirmee(Site $site, array $lignes): GrilleTarifaire
    {
        $saisie = [];
        foreach ($lignes as $niveau => $parType) {
            foreach ($parType as $typeId => $ligne) {
                [$loyer, $caution, $avance] = $ligne;
                $unite = $ligne[3] ?? config('raaga.grille_tarifaire.unite_par_type')[TypeBoutique::query()->whereKey($typeId)->value('code')] ?? 'M2';
                $saisie[$niveau][$typeId] = ['unite_loyer' => $unite, 'loyer_m2' => $loyer, 'mois_caution' => $caution, 'mois_avance' => $avance];
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
