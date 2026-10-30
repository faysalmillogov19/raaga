@props(['faces'])

{{--
    Carte d'identification à l'écran : le recto et le verso du gabarit des cartes générées (resources/views/cartes/_faces),
    identiques au PNG et au PDF. Variables : $faces (RenduCarte::ecran).
--}}
<link rel="stylesheet" href="{{ asset_versionne('css/carte-raaga.css') }}">
<div {{ $attributes->merge(['class' => 'cr-ecran']) }} data-carte-identification>
    @include('cartes._faces', ['d' => $faces])
</div>
