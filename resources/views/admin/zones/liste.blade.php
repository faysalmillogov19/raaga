@php
    use App\Enums\Droit;

    $z = __('admin.zones');
    $niveaux = $liste === 'niveaux';
    $gerer = auth()->user()->can(Droit::SitesGerer->value);
    $titre = $z[$liste.'_titre'];
@endphp

<x-admin.layout :titre="$titre" :fil="[['libelle' => $titre]]">
    @if ($gerer)
        <x-slot:actions>
            <x-admin.fenetre :id="'ajouter-'.$liste" :titre="$z[$liste.'_ajouter']" :action="route('admin.referencement.zones.store', $liste)" :bouton="$z['ajouter']" declencheur-icone="plus">
                <x-slot:declencheur>{{ $z[$liste.'_ajouter'] }}</x-slot:declencheur>
                <div class="adm-fenetre-grille">
                    <x-admin.champ nom="code" :libelle="$z['colonnes']['code']" :maxlength="$niveaux ? 3 : 5" required autocomplete="off" :aide="$z[$liste.'_code_aide']" />
                    <x-admin.champ nom="libelle" :libelle="$z['colonnes']['libelle']" maxlength="100" required />
                    @if ($niveaux)
                        <x-admin.champ nom="rang" :libelle="$z['colonnes']['rang']" type="number" min="-9" max="99" required :aide="$z['rang_aide']" />
                    @else
                        <x-admin.champ nom="description" :libelle="$z['colonnes']['description']" maxlength="300" class="adm-plein" />
                        <x-admin.champ nom="ordre" :libelle="$z['colonnes']['ordre']" type="number" min="0" max="9999" />
                    @endif
                </div>
            </x-admin.fenetre>
        </x-slot:actions>
    @endif

    <div class="bo-carte !mt-0">
        <x-tableau :t="$tableau" :libelle="$titre" />
    </div>
</x-admin.layout>
