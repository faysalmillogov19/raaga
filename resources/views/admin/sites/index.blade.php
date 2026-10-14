@php
    $gerer = auth()->user()->can(\App\Enums\Droit::SitesGerer->value);
@endphp

<x-admin.layout :titre="__('admin.sites.titre')" :fil="[['libelle' => __('admin.sites.titre')]]">
    <div class="bo-carte !mt-0">
        <div class="mb-4 flex flex-wrap items-start justify-between gap-4">
            <p class="texte-carte max-w-3xl">{{ __('admin.sites.intro') }}</p>
            @if ($gerer)
                <div class="flex flex-wrap gap-3">
                    <x-admin.bouton variante="contour" href="{{ route('admin.referencement.imports') }}">{{ __('admin.menu.imports') }}</x-admin.bouton>
                    <x-admin.bouton variante="principal" href="{{ route('admin.sites.create') }}">{{ __('admin.sites.nouveau') }}</x-admin.bouton>
                </div>
            @endif
        </div>

        <x-tableau :t="$tableau" :libelle="__('admin.sites.titre')" />
    </div>
</x-admin.layout>
