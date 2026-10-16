{{-- Remboursements : liste avec export ; le CONTRÔLEUR DES PAIEMENTS déclare l'exécution, le VALIDATEUR la confirme. --}}
@php($r = __('admin.remboursements'))

<x-admin.layout :titre="$r['titre']" :sous-titre="$r['intro']" :fil="[['libelle' => $r['titre']]]">
    <div class="bo-carte !mt-0">
        <x-tableau :t="$tableau" :libelle="$r['titre']" />
    </div>
</x-admin.layout>
