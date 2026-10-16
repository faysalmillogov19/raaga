{{-- Dossiers de paiement (CONTRÔLEUR DES PAIEMENTS) : souscriptions d'actions et cautions des boutiques, un onglet chacun. --}}
@php($p = __('admin.paiements'))

<x-admin.layout :titre="$p['titre']" :sous-titre="$p['intro']" :fil="[['libelle' => $p['titre']]]">
    <nav class="bo-onglets" aria-label="{{ $p['titre'] }}">
        @foreach ($p['vues'] as $cle => $libelle)
            <a href="{{ route('admin.paiements', ['vue' => $cle]) }}" @class(['bo-onglet', 'bo-onglet-actif' => $cle === $vue]) @if ($cle === $vue) aria-current="page" @endif>{{ $libelle }}</a>
        @endforeach
    </nav>

    <div class="bo-carte">
        <x-tableau :t="$tableau" :libelle="$p['vues'][$vue]" />
    </div>
</x-admin.layout>
