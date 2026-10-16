{{--
    Avances en retard : dossiers dont la date limite de l'avance est dépassée sans avance confirmée (boutique toujours
    réservée, aucune action automatique). RÉGULATEUR : propose une décision motivée ; VALIDATEUR : décide dans
    « À valider ». GESTIONNAIRE DES SITES ET BOUTIQUES : statuts seulement, aucun montant.
--}}
@php($a = __('admin.avances'))

<x-admin.layout :titre="$a['titre']" :sous-titre="$a['intro']" :fil="[['libelle' => $a['titre']]]">
    <div class="bo-carte !mt-0" data-avances-retard>
        <x-tableau :t="$tableau" :libelle="$a['titre']" />
    </div>
</x-admin.layout>
