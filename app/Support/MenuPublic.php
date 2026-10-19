<?php

namespace App\Support;

/**
 * Entrées du menu principal du site public, communes aux deux thèmes. Chaque entrée est active si la route courante
 * correspond à l'un de ses motifs. Deux démarches distinctes : « Investir » (actions B) et « Boutiques » ; sans
 * présentation des actions, seule la démarche « boutiques » est proposée.
 */
final class MenuPublic
{
    /** @return list<array{cle: string, route: string, motifs: list<string>, actif: bool}> */
    public static function entrees(): array
    {
        $entrees = [
            ['cle' => 'accueil', 'route' => 'accueil', 'motifs' => ['accueil']],
            ['cle' => 'investir', 'route' => 'offre', 'motifs' => ['offre', 'transparence', 'actualites.*', 'souscrire', 'souscrire.*', 'souscription.*', 'paiement', 'paiement.*']],
            ['cle' => 'boutiques', 'route' => 'boutiques', 'motifs' => ['boutiques', 'boutiques.*']],
            ['cle' => 'espace', 'route' => auth()->check() ? 'espace' : 'connexion', 'motifs' => ['espace', 'espace.*', 'connexion', 'connexion.*']],
            ['cle' => 'faq', 'route' => 'faq', 'motifs' => ['faq']],
        ];
        if (! Phase::presentationActions()) {
            $entrees = array_filter($entrees, fn ($e) => $e['cle'] !== 'investir');
        }

        return array_values(array_map(fn ($e) => [...$e, 'actif' => request()->routeIs(...$e['motifs'])], $entrees));
    }

    /**
     * Thème « nuit » : mêmes entrées et même ordre, sans « Mon espace », qui devient le bouton pilule de l'en-tête.
     *
     * @return list<array{cle: string, route: string, motifs: list<string>, actif: bool}>
     */
    public static function entreesNuit(): array
    {
        return array_values(array_filter(self::entrees(), fn ($e) => $e['cle'] !== 'espace'));
    }
}
