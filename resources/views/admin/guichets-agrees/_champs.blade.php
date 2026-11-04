{{--
    Champs d'un guichet agréé (fenêtres d'ajout et de modification) : nom, commune, adresse, horaires, téléphone.
    Variables : $guichet (guichet ou null), $prefixe (identifiant des champs), $communes (communes groupées par province).
--}}
@php($g = __('admin.guichets_agrees'))
<div class="adm-fenetre-grille">
    <x-admin.champ nom="nom" :id="$prefixe.'-nom'" :libelle="$g['colonnes']['nom']" :valeur="$guichet?->nom" maxlength="120" required class="adm-plein" />
    <x-admin.champ nom="commune_id" :id="$prefixe.'-commune'" type="liste" :libelle="$g['colonnes']['commune']" :valeur="$guichet?->commune_id"
                   :options="$communes" :vide="$g['choisir']" required class="adm-plein" />
    <x-admin.champ nom="adresse" :id="$prefixe.'-adresse'" :libelle="$g['colonnes']['adresse']" :valeur="$guichet?->adresse" maxlength="300" required class="adm-plein"
                   :aide="$g['adresse_aide']" />
    <x-admin.champ nom="horaires" :id="$prefixe.'-horaires'" :libelle="$g['colonnes']['horaires']" :valeur="$guichet?->horaires" maxlength="200" :aide="$g['horaires_aide']" />
    <x-admin.champ nom="telephone" :id="$prefixe.'-telephone'" :libelle="$g['colonnes']['telephone']" :valeur="$guichet?->telephone" type="tel" maxlength="20" inputmode="tel"
                   :aide="$g['telephone_aide']" />
</div>
