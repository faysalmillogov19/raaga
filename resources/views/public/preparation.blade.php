{{-- Parcours pas encore ouverts (souscription, paiement, espace, vérification) : état vide honnête. --}}
@php
    $citoyen = auth()->user()?->isCitoyen();
    [$icone, $lien, $libelleLien] = match ($page) {
        'souscrire' => ['souscriptions', route('offre'), __('public.etats_vides.liens.offre')],
        'paiement' => ['cadenas', route('faq'), __('public.etats_vides.liens.faq')],
        'espace' => ['utilisateur', $citoyen ? route('espace.profil') : route('offre'), $citoyen ? __('public.etats_vides.liens.profil') : __('public.etats_vides.liens.offre')],
        'verification' => ['kyc', route('faq'), __('public.etats_vides.liens.faq')],
    };
@endphp

<x-public.layout :titre="$titre">
    <x-public.page-entete :titre="$titre" :fil="[['libelle' => $titre]]" />

    <div class="conteneur py-8 tab:py-12">
        @if ($reference = session('souscription.reservee'))
            <x-public.alerte variante="info" class="mb-6" role="status">{{ __('public.souscription.reservee', ['reference' => $reference]) }}</x-public.alerte>
        @endif
        <x-etat-vide :icone="$icone" :titre="__('public.etats_vides.'.$page.'.titre')" :lien="$lien" :libelle-lien="$libelleLien">
            {{ __('public.etats_vides.'.$page.'.texte') }}
        </x-etat-vide>
    </div>
</x-public.layout>
