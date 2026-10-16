<x-admin.layout :titre="__('admin.boutiques.titre')" :fil="[['libelle' => __('admin.boutiques.titre')]]">
    <div class="bo-carte">
        <div class="mb-4 flex flex-wrap items-start justify-between gap-4">
            <p class="texte-carte max-w-3xl">{{ __('admin.boutiques.intro') }}</p>
            <div class="flex flex-wrap gap-3">
                @can(\App\Enums\Droit::GrilleVoir->value)
                    <x-admin.bouton variante="contour" href="{{ route('admin.boutiques.grilles') }}">{{ __('admin.grilles.titre') }}</x-admin.bouton>
                @endcan
                @can(\App\Enums\Droit::BoutiquesSaisir->value)
                    <x-admin.bouton variante="principal" href="{{ route('admin.boutiques.create') }}">{{ __('admin.boutiques.nouvelle') }}</x-admin.bouton>
                @endcan
            </div>
        </div>

        <x-tableau :t="$tableau" :libelle="__('admin.boutiques.titre')" />
    </div>
</x-admin.layout>
