{{-- Fenêtre « Modifier la capacité ». Variables : $capacite, $listes, $ouvrir (facultatif). --}}
@php($k = __('admin.referencement.capacites'))
<x-admin.fenetre :id="'modifier-capacite-'.$capacite->id" :titre="$k['modifier_titre']" :action="route('admin.referencement.capacites.update', $capacite)" methode="PUT"
                 :bouton="$k['enregistrer']" :ouvrir="$ouvrir ?? false">
    <x-admin.champ nom="site_affiche" :libelle="$k['colonnes']['site']" type="verrouille" :valeur="($capacite->site->code ? $capacite->site->code.' · ' : '').$capacite->site->nom" />
    <div class="adm-fenetre-grille mt-4">
        @include('admin.referencement._capacite-champs', ['capacite' => $capacite, 'listes' => $listes, 'prefixe' => 'cap-'.$capacite->id])
    </div>
</x-admin.fenetre>
