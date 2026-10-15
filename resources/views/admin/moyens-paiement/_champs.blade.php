{{--
    Champs d'un moyen de paiement (fenêtres d'ajout et de modification) : code (ajout seulement), libellé, famille,
    instructions affichées au citoyen, ordre, puis pour chaque usage : autorisé, montant minimum et maximum par
    opération (vide = sans limite). Aucune clé ni aucun secret d'opérateur : ils restent dans le .env.
    Variables : $m (moyen ou null), $prefixe (identifiant des champs).
--}}
@php
    use App\Enums\FamilleMoyenPaiement;
    use App\Enums\UsageMoyenPaiement;

    $p = __('admin.moyens_paiement');
    $z = __('admin.zones');
    $familles = collect(FamilleMoyenPaiement::cases())->mapWithKeys(fn ($f) => [$f->value => $f->label()])->all();
@endphp
<div class="adm-fenetre-grille">
    @if ($m)
        <x-admin.champ nom="code_affiche" :id="$prefixe.'-code'" :libelle="$z['colonnes']['code']" type="verrouille" :valeur="$m->code" :aide="$z['code_fige']" />
    @else
        <x-admin.champ nom="code" :id="$prefixe.'-code'" :libelle="$z['colonnes']['code']" maxlength="20" required autocomplete="off" :aide="$z['moyens_code_aide']" />
    @endif
    <x-admin.champ nom="libelle" :id="$prefixe.'-libelle'" :libelle="$z['colonnes']['libelle']" :valeur="$m?->libelle" maxlength="100" required />
    <x-admin.champ nom="famille" :id="$prefixe.'-famille'" :libelle="$p['colonnes']['famille']" type="liste" :options="$familles" :valeur="$m?->famille?->value" obligatoire :aide="$p['famille_aide']" />
    <x-admin.champ nom="ordre" :id="$prefixe.'-ordre'" :libelle="$z['colonnes']['ordre']" :valeur="$m?->ordre" type="number" min="0" max="9999" />
    <x-admin.champ nom="instructions" :id="$prefixe.'-instructions'" :libelle="$p['colonnes']['instructions']" type="zone" :valeur="$m?->instructions" maxlength="2000" class="adm-plein" :aide="$p['instructions_aide']" />
    @foreach (UsageMoyenPaiement::cases() as $usage)
        @php($u = $m?->usage($usage) ?? ['autorise' => true, 'min' => null, 'max' => null])
        <fieldset class="adm-plein rounded-lg border border-ligne p-3" data-usage="{{ $usage->value }}">
            <legend class="px-1 font-bold text-encre">{{ $usage->label() }}</legend>
            <div class="grid gap-3 sm:grid-cols-3">
                <x-admin.champ :nom="'usages['.$usage->value.'][autorise]'" :id="$prefixe.'-'.$usage->value.'-autorise'" :libelle="$p['autorise']" type="case" :valeur="$u['autorise']" />
                <x-admin.champ :nom="'usages['.$usage->value.'][min]'" :id="$prefixe.'-'.$usage->value.'-min'" :libelle="$p['minimum']" :valeur="$u['min']" type="number" min="0" :aide="$p['sans_limite']" />
                <x-admin.champ :nom="'usages['.$usage->value.'][max]'" :id="$prefixe.'-'.$usage->value.'-max'" :libelle="$p['maximum']" :valeur="$u['max']" type="number" min="0" :aide="$p['sans_limite']" />
            </div>
        </fieldset>
    @endforeach
</div>
