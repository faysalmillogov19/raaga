@props(['couleur' => 'vert', 'icone' => null, 'texte' => null, 'taille' => 'normale'])

{{--
    Rond de couleur (vert, bleu, orange, rose, cyan, rouge, jaune, vif) avec une icône (x-icon) ou un texte court
    (« ID », « QR »). Décoratif : le sens est porté par le texte voisin. Texte et icône foncés pour le contraste.
--}}
<span {{ $attributes->class(['nuit-pastille', 'nuit-pastille-'.$taille, 'nuit-c-'.\App\Support\CouleursNuit::nom($couleur)]) }} aria-hidden="true">
    @if ($icone)<x-icon :name="$icone" />@else{{ $texte }}@endif
</span>
