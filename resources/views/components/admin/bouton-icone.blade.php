@props(['icone', 'intitule', 'href' => null, 'type' => 'button', 'variante' => 'secondaire', 'infobulle' => null])

{{--
    Petit bouton carré, icône seule. L'intitulé apparaît en infobulle au survol et au focus clavier (data-infobulle),
    et reste lu par les lecteurs d'écran (aria-label). « infobulle » remplace le texte affiché (motif d'un bouton inactif).
    Lien si « href », bouton sinon. Variantes : secondaire, principal, danger.
--}}
@php
    $texte = $infobulle ?? $intitule;
    $classes = 'bo-bouton-icone bo-bouton-icone-'.$variante;
@endphp
@if ($href)
    <a href="{{ $href }}" aria-label="{{ $intitule }}" data-infobulle="{{ $texte }}" {{ $attributes->merge(['class' => $classes]) }}>
        <x-icon :name="$icone" class="h-[18px] w-[18px]" />
    </a>
@else
    <button type="{{ $type }}" aria-label="{{ $intitule }}" data-infobulle="{{ $texte }}" {{ $attributes->merge(['class' => $classes]) }}>
        <x-icon :name="$icone" class="h-[18px] w-[18px]" />
    </button>
@endif
