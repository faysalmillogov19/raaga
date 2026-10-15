@php($g = __('admin.grilles'))

<x-admin.layout :titre="$g['titre']" :fil="[['libelle' => __('admin.boutiques.titre'), 'url' => route('admin.boutiques')], ['libelle' => $g['titre']]]">
    <div class="bo-carte !mt-0">
        <p class="texte-carte max-w-3xl">{{ $g['intro'] }}</p>
        <p class="texte-carte mt-1 max-w-3xl">{{ $g['calcul'] }}</p>

        <x-tableau :t="$tableau" :libelle="$g['titre']" class="mt-4" />
    </div>
</x-admin.layout>
