@props(['barres', 'titre'])

{{--
    Graphique en barres verticales, en CSS pur. $barres = [['libelle' => axe, 'valeur' => nombre, 'etiquette' => texte
    affiché au-dessus, 'couleur' => …], …]. Hauteurs proportionnelles à la plus grande valeur. Tableau équivalent pour les
    lecteurs d'écran ; le graphique lui-même est décoratif.
--}}
@php
    use App\Support\CouleursNuit;

    $max = max(1, ...array_map(fn ($b) => $b['valeur'], $barres ?: [['valeur' => 1]]));
    $g = __('public.nuit.graphique');
@endphp
<div {{ $attributes->class(['nuit-barres-figure']) }}>
    <p class="nuit-barres-titre" aria-hidden="true">{{ $titre }}</p>
    <div class="nuit-barres" aria-hidden="true">
        @foreach (array_values($barres) as $i => $b)
            <div class="nuit-barres-colonne">
                <span class="nuit-barres-valeur">{{ $b['etiquette'] ?? format_nombre($b['valeur']) }}</span>
                <span class="nuit-barres-barre nuit-c-{{ CouleursNuit::nom($b['couleur'] ?? null, $i) }}" style="--h: {{ round($b['valeur'] / $max * 100, 1) }}%"></span>
            </div>
        @endforeach
    </div>
    <div class="nuit-barres-axe" aria-hidden="true">
        @foreach ($barres as $b)<span>{{ $b['libelle'] }}</span>@endforeach
    </div>
    <table class="sr-only">
        <caption>{{ $titre }}</caption>
        <thead><tr><th scope="col">{{ $g['libelle'] }}</th><th scope="col">{{ $g['valeur'] }}</th></tr></thead>
        <tbody>
            @foreach ($barres as $b)
                <tr><th scope="row">{{ $b['libelle'] }}</th><td>{{ $b['etiquette'] ?? format_nombre($b['valeur']) }}</td></tr>
            @endforeach
        </tbody>
    </table>
</div>
