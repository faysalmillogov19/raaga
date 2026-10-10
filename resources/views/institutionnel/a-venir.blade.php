<x-institutionnel.layout :titre="__('institutionnel.menu.'.$rubrique)" :fil="[['libelle' => __('institutionnel.menu.'.$rubrique)]]">
    <div class="bo-carte !mt-0">
        <p class="font-bold text-encre">{{ __('institutionnel.a_venir') }}</p>
        <p class="texte-carte mt-1">{{ __('institutionnel.a_venir_'.$rubrique) }}</p>
    </div>
</x-institutionnel.layout>
