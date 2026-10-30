<?php

namespace App\Services\Cartes;

use RuntimeException;

/** Génération d'une carte refusée : un réglage requis manque (et la carte n'a pas encore de réglages figés). */
class ReglagesCarteManquants extends RuntimeException
{
    /** @param list<string> $manquants clés des champs (admin.plateforme.champs) */
    public function __construct(public readonly array $manquants)
    {
        parent::__construct(__('carte.reglages_manquants', [
            'champs' => implode(', ', array_map(fn ($c) => __('admin.plateforme.champs.'.$c), $manquants)),
        ]));
    }
}
