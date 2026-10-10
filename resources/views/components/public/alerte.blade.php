@props(['variante' => 'info', 'titre' => null])

@php
    // Icône placée devant le titre (ou le texte) de l'encadré ; le sens est porté par le texte.
    $icone = ['danger' => 'danger', 'attention' => 'danger', 'info' => 'info'][$variante];
@endphp

<div {{ $attributes->merge(['class' => "encadre encadre-{$variante}"]) }}>
    @if ($titre)
        <h3 class="flex items-center gap-2"><x-icon :name="$icone" class="encadre-symbole h-5 w-5" /> {{ $titre }}</h3>
        <div>{{ $slot }}</div>
    @else
        <div class="flex items-start gap-2"><x-icon :name="$icone" class="encadre-symbole mt-0.5 h-5 w-5" /> <div>{{ $slot }}</div></div>
    @endif
</div>
