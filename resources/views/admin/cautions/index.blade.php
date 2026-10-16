{{--
    Cautions à rembourser : réservations annulées automatiquement faute d'avance, dont le remboursement de la caution
    attend la retenue. RÉGULATEUR : propose la retenue ; VALIDATEUR : décide dans « À valider » ; GESTIONNAIRE DES
    SITES ET BOUTIQUES : statuts seulement, aucun montant.
--}}
@php($a = __('admin.avances'))

<x-admin.layout :titre="$a['titre']" :sous-titre="$a['intro']" :fil="[['libelle' => $a['titre']]]">
    <div class="bo-carte !mt-0" data-cautions-a-rembourser>
        <x-tableau :t="$tableau" :libelle="$a['titre']" />
    </div>
</x-admin.layout>
