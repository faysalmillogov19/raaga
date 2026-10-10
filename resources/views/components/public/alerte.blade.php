@props(['variante' => 'info', 'titre' => null])

@php
    // Symbole texte placé devant le titre (ou le texte) de l'encadré.
    $symbole = ['danger' => '⚠︎', 'attention' => 'ℹ︎', 'info' => 'ℹ︎'][$variante];
@endphp

<div {{ $attributes->merge(['class' => "encadre encadre-{$variante}"]) }}>
    @if ($titre)
        <h3><span class="encadre-symbole" aria-hidden="true">{{ $symbole }}</span> {{ $titre }}</h3>
        <div>{{ $slot }}</div>
    @else
        <div><span class="encadre-symbole font-bold" aria-hidden="true">{{ $symbole }}</span> {{ $slot }}</div>
    @endif
</div>
