@props(['segments', 'titre', 'unite' => null])

{{--
    Graphique en anneau, en CSS pur (conic-gradient). $segments = [['libelle' => …, 'valeur' => nombre, 'couleur' => …], …].
    L'anneau et la légende sont décoratifs pour les lecteurs d'écran : un tableau équivalent (masqué à l'écran) les remplace.
--}}
@php
    use App\Support\CouleursNuit;

    $total = max(0, array_sum(array_column($segments, 'valeur')));
    $cumul = 0;
    $arrets = [];
    foreach (array_values($segments) as $i => $s) {
        $debut = $total > 0 ? round($cumul / $total * 100, 2) : 0;
        $cumul += $s['valeur'];
        $fin = $total > 0 ? round($cumul / $total * 100, 2) : 0;
        $arrets[] = CouleursNuit::HEX[CouleursNuit::nom($s['couleur'] ?? null, $i)].' '.$debut.'% '.$fin.'%';
    }
    $fond = $total > 0 ? 'conic-gradient('.implode(', ', $arrets).')' : 'var(--nuit-bordure)';
    $g = __('public.nuit.graphique');
@endphp
<figure {{ $attributes->class(['nuit-anneau-figure']) }}>
    <div class="nuit-anneau" style="--anneau: {{ $fond }}" aria-hidden="true"><span class="nuit-anneau-centre"></span></div>
    <figcaption aria-hidden="true">
        <p class="nuit-legende-titre">{{ $titre }}</p>
        <ul class="nuit-legende">
            @foreach (array_values($segments) as $i => $s)
                <li><span class="nuit-legende-carre nuit-c-{{ CouleursNuit::nom($s['couleur'] ?? null, $i) }}"></span>{{ $s['libelle'] }} · {{ format_nombre($s['valeur']) }}{{ $unite ? ' '.$unite : '' }}</li>
            @endforeach
        </ul>
    </figcaption>
    <table class="sr-only">
        <caption>{{ $titre }}</caption>
        <thead><tr><th scope="col">{{ $g['libelle'] }}</th><th scope="col">{{ $g['valeur'] }}</th><th scope="col">{{ $g['part'] }}</th></tr></thead>
        <tbody>
            @foreach ($segments as $s)
                <tr><th scope="row">{{ $s['libelle'] }}</th><td>{{ format_nombre($s['valeur']) }}{{ $unite ? ' '.$unite : '' }}</td>
                    <td>{{ $total > 0 ? str_replace('.', ',', (string) round($s['valeur'] / $total * 100, 1)).' %' : '0 %' }}</td></tr>
            @endforeach
        </tbody>
    </table>
</figure>
