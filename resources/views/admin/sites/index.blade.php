@php
    $gerer = auth()->user()->can(\App\Enums\Droit::SitesGerer->value);
@endphp

<x-admin.layout :titre="__('admin.sites.titre')" :fil="[['libelle' => __('admin.sites.titre')]]">
    <div class="bo-carte !mt-0">
        <div class="mb-4 flex flex-wrap items-start justify-between gap-4">
            <p class="texte-carte max-w-3xl">{{ __('admin.sites.intro') }}</p>
            @if ($gerer)
                <div class="flex flex-wrap gap-3">
                    <a href="{{ route('admin.referencement.imports') }}" class="bo-bouton bo-bouton-secondaire">{{ __('admin.menu.imports') }}</a>
                    <a href="{{ route('admin.sites.create') }}" class="bo-bouton bo-bouton-principal">{{ __('admin.sites.nouveau') }}</a>
                </div>
            @endif
        </div>

        <x-tableau :t="$tableau" :libelle="__('admin.sites.titre')" />
    </div>
</x-admin.layout>
