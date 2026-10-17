@props(['parent'])

{{--
    Boutons Retour et Suivant (à côté du fil d'Ariane) : Retour = history.back(), ou la page parente du fil quand la page
    a été ouverte directement ; Suivant = history.forward(), grisé quand le navigateur indique qu'il n'y a pas de page
    suivante (navigation.canGoForward). Jamais dans les popups, ni à l'impression, ni dans les PDF.
--}}
@php
    \App\Support\NavigationHistorique::marquer();
    $n = __('public.navigation_historique');
@endphp
<div {{ $attributes->merge(['class' => 'nav-historique']) }} data-navigation-historique>
    <button type="button" class="nav-historique-bouton" data-historique-retour data-parent="{{ $parent }}" title="{{ $n['retour'] }}">
        <x-icon name="fleche-retour" /><span class="sr-only">{{ $n['retour_long'] }}</span>
    </button>
    <button type="button" class="nav-historique-bouton" data-historique-suivant title="{{ $n['suivant'] }}">
        <x-icon name="fleche" /><span class="sr-only">{{ $n['suivant_long'] }}</span>
    </button>
</div>
