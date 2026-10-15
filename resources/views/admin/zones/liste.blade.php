@php
    use App\Http\Controllers\Admin\NiveauxZonesController;

    $z = __('admin.zones');
    $niveaux = $liste === 'niveaux';
    $unites = $liste === 'unites';
    $moyens = $liste === 'moyens';
    $types = collect(\App\Enums\TypeMoyenPaiement::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()])->all();
    $gerer = auth()->user()->can(NiveauxZonesController::droit($liste, 'gerer')->value);
    $titre = $z[$liste.'_titre'];
@endphp

<x-admin.layout :titre="$titre" :fil="[['libelle' => $titre]]">
    @if ($gerer)
        <x-slot:actions>
            <x-admin.fenetre :id="'ajouter-'.$liste" :titre="$z[$liste.'_ajouter']" :action="route('admin.referencement.zones.store', $liste)" :bouton="$z['ajouter']" declencheur-icone="plus" soumission>
                <x-slot:declencheur>{{ $z[$liste.'_ajouter'] }}</x-slot:declencheur>
                <div class="adm-fenetre-grille">
                    <x-admin.champ nom="code" :libelle="$z['colonnes']['code']" :maxlength="$niveaux ? 3 : ($unites ? 10 : ($moyens ? 20 : 5))" required autocomplete="off" :aide="$z[$liste.'_code_aide']" />
                    <x-admin.champ nom="libelle" :libelle="$z['colonnes']['libelle']" maxlength="100" required />
                    @if ($niveaux)
                        <x-admin.champ nom="rang" :libelle="$z['colonnes']['rang']" type="number" min="-9" max="99" required :aide="$z['rang_aide']" />
                    @else
                        @if ($moyens)
                            <x-admin.champ nom="type" :libelle="$z['colonnes']['type']" type="liste" :options="$types" obligatoire :aide="$z['type_aide']" />
                        @endif
                        <x-admin.champ nom="description" :libelle="$z['colonnes']['description']" maxlength="300" class="adm-plein" />
                        @unless ($unites)
                            <x-admin.champ nom="ordre" :libelle="$z['colonnes']['ordre']" type="number" min="0" max="9999" />
                        @endunless
                    @endif
                </div>
            </x-admin.fenetre>
        </x-slot:actions>
    @endif

    <div class="bo-carte !mt-0">
        <x-tableau :t="$tableau" :libelle="$titre" />
    </div>
</x-admin.layout>
