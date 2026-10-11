<x-admin.layout :titre="__('admin.tranches.actions.reglement')" :fil="[['libelle' => __('admin.tranches.titre'), 'url' => route('admin.tranches')], ['libelle' => $tranche->nom, 'url' => route('admin.tranches.show', $tranche)], ['libelle' => __('admin.tranches.actions.reglement')]]">
    <article class="bo-carte max-w-[820px] space-y-4 leading-relaxed">
        @include('documents._reglement-tranche')
    </article>
</x-admin.layout>
