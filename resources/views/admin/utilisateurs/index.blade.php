<x-admin.layout :titre="__('admin.utilisateurs.titre')" :fil="[['libelle' => __('admin.utilisateurs.titre')]]">
    <div class="bo-carte !mt-0">
        <div class="mb-4 flex flex-wrap items-start justify-between gap-4">
            <p class="texte-carte max-w-3xl">{{ __('admin.utilisateurs.intro.backoffice') }}</p>
            <x-admin.bouton variante="principal" href="{{ route('admin.utilisateurs.inviter') }}">{{ __('admin.utilisateurs.inviter') }}</x-admin.bouton>
        </div>

        <x-tableau :t="$tableau" :libelle="__('admin.utilisateurs.titre')" />
    </div>
</x-admin.layout>
