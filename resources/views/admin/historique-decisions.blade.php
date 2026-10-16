{{-- Historique de mes décisions : confirmations, rejets, autorisations et attributions du VALIDATEUR connecté. --}}
@php($h = __('admin.historique_decisions'))

<x-admin.layout :titre="$h['titre']">
    <div class="bo-carte !mt-0" data-historique-decisions>
        <p class="texte-carte max-w-3xl">{{ $h['intro'] }}</p>

        <x-tableau :t="$tableau" :libelle="$h['titre']" class="mt-4" />
    </div>
</x-admin.layout>
