@props(['onglets' => [], 'actif' => null, 'libelle' => null])

{{-- Onglets de navigation : $onglets = [clé => [libellé, adresse]] ; l'onglet actif porte aria-current. --}}
<nav {{ $attributes->merge(['class' => 'adm-onglets']) }} @if ($libelle) aria-label="{{ $libelle }}" @endif>
    <ul>
        @foreach ($onglets as $cle => [$texte, $url])
            <li>
                <a href="{{ $url }}" @class(['adm-onglet', 'adm-onglet-actif' => $cle === $actif]) @if ($cle === $actif) aria-current="page" @endif>{{ $texte }}</a>
            </li>
        @endforeach
    </ul>
</nav>
