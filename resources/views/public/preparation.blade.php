<x-public.layout :titre="$titre">
    <x-public.page-entete :titre="$titre" :intro="$intro" :fil="[['libelle' => $titre]]" />

    <div class="conteneur max-w-3xl py-8 tab:py-12">
        <x-public.alerte variante="info" :titre="__('public.preparation.titre_alerte')">
            {{ __('public.preparation.texte') }}
        </x-public.alerte>

        <p class="mt-6 leading-relaxed">{{ __('public.preparation.en_attendant') }}</p>
        <div class="mt-4 flex flex-col gap-3 sm:flex-row">
            <a href="{{ route('offre') }}" class="btn-primaire">{{ __('public.menu.offre') }}</a>
            <a href="{{ route('faq') }}" class="btn-secondaire">{{ __('public.menu.faq') }}</a>
        </div>
    </div>
</x-public.layout>
