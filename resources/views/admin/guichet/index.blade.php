{{-- Guichet : vérifier une carte d'accès Raaga (QR code scanné ou numéro saisi). --}}
@php($g = __('admin.guichet'))

<x-admin.layout :titre="$g['titre']" :fil="[['libelle' => $g['titre']]]">
    <section class="bo-carte !mt-0" aria-labelledby="guichet-titre">
        <h2 id="guichet-titre" class="!text-[1.15rem]">{{ $g['verifier'] }}</h2>
        <p class="texte-carte mt-1 max-w-3xl">{{ $g['intro'] }}</p>
        <form method="POST" action="{{ route('admin.guichet.rechercher') }}" class="bo-filtres mt-4">
            @csrf
            <x-admin.champ nom="carte" :libelle="$g['carte']" :aide="$g['carte_aide']" maxlength="300" autocomplete="off" required class="min-w-[260px] flex-1" />
            <x-admin.bouton type="submit" icone="recherche">{{ $g['bouton'] }}</x-admin.bouton>
        </form>
    </section>
</x-admin.layout>
