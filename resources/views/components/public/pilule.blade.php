@props(['variante' => 'plein', 'href' => null, 'type' => 'button', 'presse' => null])

{{--
    Bouton pilule du thème « nuit » : « plein » (vert foncé), « contour », « choix » (bascule : aria-pressed, vert vif
    quand choisie) ou « etiquette » (non cliquable). Lien si « href », sinon bouton.
--}}
@php($classes = 'nuit-pilule nuit-pilule-'.$variante)
@if ($href)
    <a href="{{ $href }}" {{ $attributes->class([$classes]) }}>{{ $slot }}</a>
@elseif ($variante === 'etiquette')
    <span {{ $attributes->class([$classes]) }}>{{ $slot }}</span>
@else
    <button type="{{ $type }}" {{ $attributes->class([$classes])->merge($variante === 'choix' && $presse !== null ? ['aria-pressed' => $presse ? 'true' : 'false'] : []) }}>{{ $slot }}</button>
@endif
