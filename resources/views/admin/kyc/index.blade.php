{{-- Vérifications d'identité : dossiers des citoyens (statut, proposition d'annulation en attente). Aucun montant. --}}
@php($k = __('admin.kyc'))

<x-admin.layout :titre="$k['titre']" :sous-titre="$k['intro']" :fil="[['libelle' => $k['titre']]]">
    <div class="bo-carte !mt-0">
        <x-tableau :t="$tableau" :libelle="$k['titre']" />
    </div>
</x-admin.layout>
