@props(['entetes' => [], 'lignes' => [], 'legende', 'largeurMin' => '760px'])

{{--
    Tableau du thème « nuit » : lignes zébrées ; défilement horizontal dans son cadre (zone focalisable au clavier),
    jamais celui de la page. Cellules : texte ou HTML sûr (Htmlable). Sans $lignes : le slot fournit le <tbody>.
--}}
<div class="nuit-tableau-cadre" role="region" aria-label="{{ $legende }}" tabindex="0">
    <table {{ $attributes->class(['nuit-tableau']) }} style="--largeur-min: {{ $largeurMin }}">
        <caption class="sr-only">{{ $legende }}</caption>
        @if ($entetes)
            <thead><tr>@foreach ($entetes as $entete)<th scope="col">{{ $entete }}</th>@endforeach</tr></thead>
        @endif
        @if ($lignes)
            <tbody>
                @foreach ($lignes as $ligne)
                    <tr>@foreach ($ligne as $cellule)<td>{{ $cellule }}</td>@endforeach</tr>
                @endforeach
            </tbody>
        @else
            {{ $slot }}
        @endif
    </table>
</div>
