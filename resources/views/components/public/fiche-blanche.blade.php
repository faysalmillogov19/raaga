@props(['surtitre' => null, 'titre' => null, 'lignes' => [], 'totalLibelle' => null, 'total' => null])

{{--
    Encadré clair sur fond sombre (montants). $lignes = [['libelle' => …, 'valeur' => …, 'montant' => bool, 'separe' => bool], …] :
    « montant » met le libellé en encre, « separe » trace un filet après la ligne. Total en vert en bas ; actions dans le slot.
--}}
<div {{ $attributes->class(['nuit-fiche']) }}>
    @if ($surtitre)<p class="nuit-fiche-surtitre">{{ $surtitre }}</p>@endif
    @if ($titre)<p class="nuit-fiche-titre">{{ $titre }}</p>@endif
    @if ($lignes)
        <dl class="nuit-fiche-lignes">
            @foreach ($lignes as $ligne)
                <div @class(['nuit-fiche-ligne', 'est-montant' => $ligne['montant'] ?? false, 'est-separee' => $ligne['separe'] ?? false])>
                    <dt>{{ $ligne['libelle'] }}</dt>
                    <dd>{{ $ligne['valeur'] }}</dd>
                </div>
            @endforeach
        </dl>
    @endif
    @if ($total !== null)
        <p class="nuit-fiche-total-libelle">{{ $totalLibelle }}</p>
        <p class="nuit-fiche-total">{{ $total }}</p>
    @endif
    {{ $slot }}
</div>
