@props(['variante' => 'principal', 'taille' => 'normal', 'icone' => null, 'chargement' => false, 'href' => null, 'type' => 'button', 'libelle' => null])

{{--
    Bouton du back-office. Variantes : principal, secondaire, contour, discret, danger, rejet (contour rouge).
    Tailles : petit (34 px, tableaux), normal (44 px), grand (52 px, formulaires). Lien si « href ».
    Icône seule (sans contenu) : « libelle » obligatoire, lu par les lecteurs d'écran (aria-label) et affiché au survol.
    « chargement » : indicateur animé, aria-busy, bouton inactif.
--}}
@php
    $seule = $icone && trim((string) $slot) === '';
    if ($seule && ! $libelle) {
        throw new InvalidArgumentException('<x-admin.bouton> : un bouton icône seule exige « libelle » (aria-label).');
    }
    $classes = 'adm-btn adm-btn-'.$variante.' adm-btn-'.$taille.($seule ? ' adm-btn-icone' : '').($chargement ? ' adm-btn-charge' : '');
    $attributs = $attributes->merge(['class' => $classes]);
@endphp
@if ($href && ! $chargement && ! $attributes->get('disabled'))
    <a href="{{ $href }}" {{ $attributs }} @if ($seule) aria-label="{{ $libelle }}" title="{{ $libelle }}" @endif>
        @if ($icone)<x-icon :name="$icone" class="adm-btn-svg" />@endif
        @unless ($seule)<span>{{ $slot }}</span>@endunless
    </a>
@else
    <button type="{{ $type }}" {{ $attributs }} @if ($seule) aria-label="{{ $libelle }}" title="{{ $libelle }}" @endif
            @if ($chargement) aria-busy="true" disabled @endif>
        @if ($chargement)
            <span class="adm-btn-roue" aria-hidden="true"></span>
        @elseif ($icone)
            <x-icon :name="$icone" class="adm-btn-svg" />
        @endif
        @unless ($seule)<span>{{ $slot }}</span>@endunless
    </button>
@endif
