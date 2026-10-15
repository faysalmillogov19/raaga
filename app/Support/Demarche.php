<?php

namespace App\Support;

/**
 * Deux démarches du site public, chacune avec son code visuel : « investir » (accent or, pièce avec une pousse) et
 * « boutiques » (accent vert #00843D, boutique avec auvent). Démarche de la page affichée, d'après sa route.
 */
final class Demarche
{
    public const PICTOS = ['investir' => 'piece-pousse', 'boutiques' => 'boutique-auvent'];

    public static function courante(): ?string
    {
        return match (true) {
            request()->routeIs('offre', 'transparence', 'actualites.*', 'souscrire', 'souscrire.*', 'souscription.*', 'paiement', 'paiement.*', 'espace.documents.*') => 'investir',
            request()->routeIs('boutiques', 'boutiques.*') => 'boutiques',
            default => null,
        };
    }
}
