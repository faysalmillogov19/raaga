<x-institutionnel.layout :titre="__('institutionnel.menu.appels')" :fil="[['libelle' => __('institutionnel.menu.appels')]]">
    <div class="bo-carte !mt-0">
        @include('institutionnel.appels._tableau', ['appels' => $appels])
    </div>
</x-institutionnel.layout>
