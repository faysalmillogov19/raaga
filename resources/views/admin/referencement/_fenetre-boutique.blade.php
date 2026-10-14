{{--
    Fenêtre « Modifier la boutique » : fiche d'une boutique libre (site en brouillon ou en révision). Niveau, bloc et
    numéro ne se saisissent que tant que le code manque. Variables : $boutique, $types, $ouvrir (facultatif).
--}}
@php
    $f = __('admin.sites.boutiques');
    $numeroMax = str_repeat('9', \App\Models\Boutique::CHIFFRES_NUMERO);
@endphp
<x-admin.fenetre :id="'modifier-boutique-'.$boutique->id" :titre="__('admin.sites.boutiques.fiche_titre', ['code' => $boutique->reference()])"
                 :action="route('admin.referencement.boutiques.update', $boutique)" methode="PUT" :bouton="$f['enregistrer']" :ouvrir="$ouvrir ?? false">
    @if ($declencheur ?? false)
        <x-slot:declencheur>{{ __('admin.referencement.boutiques.modifier') }}</x-slot:declencheur>
    @endif
    @if ($boutique->code)
        <p class="texte-carte mb-3">{{ __('admin.sites.boutiques.elements_figes', ['code' => $boutique->code]) }}</p>
        <div class="adm-fenetre-grille">
            <x-admin.champ nom="niveau_affiche" :libelle="$f['niveau']" type="verrouille" :valeur="$boutique->libelleNiveau()" />
            <x-admin.champ nom="bloc_affiche" :libelle="$f['bloc']" type="verrouille" :valeur="$boutique->bloc" />
        </div>
    @else
        <p class="texte-carte mb-3">{{ __('admin.sites.boutiques.code_a_venir', ['site' => $boutique->site->code ?? '…']) }}</p>
        <div class="adm-fenetre-grille">
            @include('admin.sites._champs-code', ['prefixe' => 'fiche-'.$boutique->id, 'boutique' => $boutique])
            <x-admin.champ nom="numero" :id="'fiche-'.$boutique->id.'-numero'" :libelle="$f['numero']" type="number" min="1" max="{{ $numeroMax }}" required
                           :valeur="preg_match('/(\d+)$/', (string) $boutique->numero, $m) ? (int) $m[1] : null" :aide="$f['numero_aide']" />
        </div>
    @endif
    <div class="adm-fenetre-grille mt-4">
        @include('admin.sites._champs-fiche', ['prefixe' => 'fiche-'.$boutique->id, 'boutique' => $boutique])
    </div>
</x-admin.fenetre>
