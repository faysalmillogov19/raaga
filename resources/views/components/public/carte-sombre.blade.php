@props(['titre' => null, 'niveau' => 3])

{{-- Carte du thème « nuit » : fond #13263D, coins de 10 px ; titre facultatif. --}}
<div {{ $attributes->class(['nuit-carte']) }}>
    @if ($titre)<h{{ $niveau }} class="nuit-carte-titre">{{ $titre }}</h{{ $niveau }}>@endif
    {{ $slot }}
</div>
