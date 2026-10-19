@props(['fond' => 'principal', 'surtitre' => null, 'titre' => null, 'fort' => null, 'centre' => false, 'niveau' => 2])

{{--
    Section du thème « nuit » : fond principal, alterné ou blanc ; surtitre vert vif et titre « léger + gras »
    (titre, puis fort en gras). Contenu dans le conteneur de 1 180 px.
--}}
<section {{ $attributes->class(['nuit-section', 'nuit-section-'.$fond]) }}>
    <div class="conteneur nuit-section-corps">
        @if ($surtitre || $titre || $fort)
            <div @class(['nuit-section-entete', 'est-centre' => $centre])>
                @if ($surtitre)<p class="nuit-surtitre">{{ $surtitre }}</p>@endif
                @if ($titre || $fort)
                    <h{{ $niveau }} class="nuit-titre">{{ $titre }}{{ $titre && $fort ? ' ' : '' }}@if ($fort)<strong>{{ $fort }}</strong>@endif</h{{ $niveau }}>
                @endif
            </div>
        @endif
        {{ $slot }}
    </div>
</section>
