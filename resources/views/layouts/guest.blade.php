<x-public.layout :titre="$titre">
    <x-public.page-entete :titre="$titre" :intro="$intro" :fil="[['libelle' => $titre]]" />

    <div class="conteneur max-w-[640px] py-8 tab:py-12">
        <div class="carte p-5 tab:p-8">
            {{ $slot }}
        </div>

        {{ $apres ?? '' }}
    </div>
</x-public.layout>
