@php
    use App\Enums\Droit;

    $t = __('admin.types');
    $gerer = auth()->user()->can(Droit::SitesGerer->value);
@endphp

<x-admin.layout :titre="$t['titre']" :fil="[['libelle' => __('admin.sites.titre'), 'url' => route('admin.sites')], ['libelle' => $t['titre']]]">
    @if ($gerer)
        <x-slot:actions>
            <x-admin.fenetre id="ajouter-type" :titre="$t['ajouter_titre']" :action="route('admin.sites.types.store')" :bouton="$t['ajouter']" declencheur-icone="plus" soumission>
                <x-slot:declencheur>{{ $t['ajouter_titre'] }}</x-slot:declencheur>
                <div class="adm-fenetre-grille">
                    <x-admin.champ nom="code" :libelle="$t['champs']['code']" maxlength="5" required autocomplete="off" :aide="$t['code_aide']" />
                    <x-admin.champ nom="libelle" :libelle="$t['champs']['libelle']" maxlength="80" required />
                    <x-admin.champ nom="description" :libelle="$t['champs']['description']" maxlength="300" class="adm-plein" />
                    <x-admin.champ nom="ordre" :libelle="$t['champs']['ordre']" type="number" min="0" max="9999" />
                    <x-admin.champ nom="attribuable" :libelle="$t['champs']['attribuable']" type="liste" :options="['1' => $t['oui'], '0' => $t['non']]" valeur="1" required />
                </div>
            </x-admin.fenetre>
        </x-slot:actions>
    @endif

    <div class="bo-carte !mt-0">
        <x-tableau :t="$tableau" :libelle="$t['titre']" />
    </div>
</x-admin.layout>
