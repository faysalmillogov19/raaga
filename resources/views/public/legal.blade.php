<x-public.layout :titre="$titre">
    <x-public.page-entete :titre="$titre" :fil="[['libelle' => $titre]]" />

    <div class="conteneur max-w-3xl py-8 tab:py-12">
        @include('public.partials.legal-contenu', ['page' => $page])
        <a href="{{ route('accueil') }}" class="btn-secondaire mt-6">{{ __('public.communs.retour_accueil') }}</a>
    </div>
</x-public.layout>
