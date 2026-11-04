{{--
    Champs d'un secteur d'activité (fenêtres d'ajout et de modification) : libellé, famille, ordre dans la famille.
    Variables : $secteur (secteur ou null), $prefixe (identifiant des champs), $familles (id => libellé).
--}}
@php($s = __('admin.secteurs'))
<div class="adm-fenetre-grille">
    <x-admin.champ nom="libelle" :id="$prefixe.'-libelle'" :libelle="$s['colonnes']['libelle']" :valeur="$secteur?->libelle" maxlength="160" required class="adm-plein" />
    <x-admin.champ nom="famille_secteur_id" :id="$prefixe.'-famille'" type="liste" :libelle="$s['colonnes']['famille']" :valeur="$secteur?->famille_secteur_id"
                   :options="$familles" :vide="$s['choisir']" required />
    <x-admin.champ nom="ordre" :id="$prefixe.'-ordre'" :libelle="$s['colonnes']['ordre']" :valeur="$secteur?->ordre" type="number" min="0" max="9999" :aide="$s['ordre_aide']" />
</div>
