@props(['statut', 'variante' => null])

{{-- Statut toujours écrit en toutes lettres ; la couleur (neutre, info, ok, avertissement, erreur) aide seulement. --}}
@php($variante ??= \App\Support\BadgeStatut::variante($statut))
<span {{ $attributes->merge(['class' => 'adm-badge adm-badge-'.$variante]) }}>{{ \App\Support\BadgeStatut::texte($statut) }}</span>
