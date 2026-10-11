{{-- Règlement de la tranche ouverte (version acceptée lors de la souscription). --}}
<x-public.layout :titre="__('public.souscription.reglement_titre')">
    <x-public.page-entete :titre="__('public.souscription.reglement_titre')" :fil="[['libelle' => __('public.preparation.pages.souscrire.titre'), 'url' => route('souscrire')], ['libelle' => __('public.souscription.reglement_titre')]]" />

    <div class="conteneur max-w-3xl py-8 tab:py-12">
        <article class="carte space-y-4 p-5 leading-relaxed tab:p-8">
            @include('documents._reglement-tranche')
        </article>
        <a href="{{ route('souscrire') }}" class="btn-secondaire mt-6">{{ __('public.souscription.retour') }}</a>
    </div>
</x-public.layout>
