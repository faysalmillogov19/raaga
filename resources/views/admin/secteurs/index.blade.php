{{--
    Secteurs d'activité des commerçants (SUPER_ADMIN) : tableau de données, fenêtre d'ajout ; modification et activation
    sur chaque ligne. Jamais de suppression.
--}}
@php($s = __('admin.secteurs'))

<x-admin.layout :titre="$s['titre']" :sous-titre="$s['intro']" :fil="[['libelle' => $s['titre']]]">
    <x-slot:actions>
        <x-admin.fenetre id="ajouter-secteur" :titre="$s['ajouter']" :action="route('admin.secteurs.store')" :bouton="$s['ajouter_bouton']" declencheur-icone="plus">
            <x-slot:declencheur>{{ $s['ajouter'] }}</x-slot:declencheur>
            @include('admin.secteurs._champs', ['secteur' => null, 'prefixe' => 'ajout'])
        </x-admin.fenetre>
    </x-slot:actions>

    <div class="bo-carte !mt-0">
        <x-tableau :t="$tableau" :libelle="$s['titre']" />
    </div>
</x-admin.layout>
