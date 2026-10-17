<?php

namespace App\Support;

/**
 * Boutons Retour et Suivant : posés à côté du fil d'Ariane (en-tête des pages publiques) ; la mise en page publique
 * les place en haut du contenu quand la page n'a pas de fil. Le marqueur vit dans la requête (une page à la fois).
 */
final class NavigationHistorique
{
    public static function marquer(): void
    {
        request()->attributes->set('navigation_historique', true);
    }

    public static function placee(): bool
    {
        return (bool) request()->attributes->get('navigation_historique', false);
    }

    /**
     * Page parente : le dernier lien du fil d'Ariane avant la page courante, sinon l'accueil de l'espace.
     *
     * @param  list<array{libelle: string, url?: ?string}>  $fil
     */
    public static function parente(array $fil, string $accueil): string
    {
        return collect($fil)->slice(0, -1)->reverse()->first(fn (array $e) => ! empty($e['url']))['url'] ?? $accueil;
    }
}
