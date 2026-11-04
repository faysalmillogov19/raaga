{{--
    Guichets agréés (SUPER_ADMIN) : tableau de données, fenêtre d'ajout ; modification, activation et suppression sur
    chaque ligne. Seuls les guichets actifs paraissent sur « Trouver un guichet ».
--}}
@php($g = __('admin.guichets_agrees'))

<x-admin.layout :titre="$g['titre']" :sous-titre="$g['intro']" :fil="[['libelle' => $g['titre']]]">
    <x-slot:actions>
        <x-admin.fenetre id="ajouter-guichet" :titre="$g['ajouter']" :action="route('admin.guichets-agrees.store')" :bouton="$g['ajouter_bouton']" declencheur-icone="plus">
            <x-slot:declencheur>{{ $g['ajouter'] }}</x-slot:declencheur>
            @include('admin.guichets-agrees._champs', ['guichet' => null, 'prefixe' => 'ajout'])
        </x-admin.fenetre>
    </x-slot:actions>

    <div class="bo-carte !mt-0">
        <x-tableau :t="$tableau" :libelle="$g['titre']" />
    </div>
</x-admin.layout>
