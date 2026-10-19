@php
    use App\Models\GrilleTarifaire;

    $g = __('admin.grilles');
    $config = config('raaga.grille_tarifaire');
    $titre = $g['modifier'].' · '.$site->nom;
@endphp

<x-admin.layout :titre="$titre" :fil="[['libelle' => __('admin.boutiques.titre'), 'url' => route('admin.boutiques')], ['libelle' => $g['titre'], 'url' => route('admin.boutiques.grilles')], ['libelle' => $site->nom, 'url' => route('admin.boutiques.grilles.show', $site)], ['libelle' => $g['modifier']]]">
    {{-- Remplir avec Excel : fichier à compléter (une ligne par niveau et type), puis import du fichier rempli. --}}
    @php
        $x = $g['excel'];
    @endphp
    <section class="bo-carte grille-excel !mt-0" aria-labelledby="excel-titre" data-grille-excel>
        <h2 id="excel-titre" class="!text-[1.15rem]">{{ $x['titre'] }}</h2>
        <p class="texte-carte mt-1 max-w-3xl">{{ $x['intro'] }}</p>
        <ol class="grille-excel-etapes mt-3">
            @foreach ($x['etapes'] as $etape)
                <li>{{ $etape }}</li>
            @endforeach
        </ol>
        <div class="mt-4 flex flex-wrap items-end gap-4">
            <x-admin.bouton variante="secondaire" icone="exporter" :href="route('admin.boutiques.grilles.excel', $site)">{{ $x['telecharger'] }}</x-admin.bouton>
            <form method="POST" action="{{ route('admin.boutiques.grilles.importer', $site) }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-3" data-import-grille>
                @csrf
                <div class="formulaire-champ adm-champ !mt-0">
                    <label for="fichier-grille" class="adm-libelle"><x-requis /> {{ $x['fichier'] }}</label>
                    <input id="fichier-grille" name="fichier" type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required class="champ">
                </div>
                <x-admin.bouton type="submit" icone="importer">{{ $x['importer'] }}</x-admin.bouton>
            </form>
        </div>
        <p class="texte-carte mt-2">{{ $x['remplace'] }}</p>
        @if (session('erreurs_import'))
            <div class="mt-4" role="alert" data-erreurs-import>
                <p class="font-semibold text-rouge">{{ $x['erreurs_titre'] }}</p>
                <div class="mt-2 overflow-x-auto">
                    <table class="bo-tableau">
                        <thead><tr>@foreach ($x['erreurs_colonnes'] as $libelle)<th scope="col">{{ $libelle }}</th>@endforeach</tr></thead>
                        <tbody>
                            @foreach (session('erreurs_import') as $e)
                                <tr><td>{{ $e['ligne'] ?? '' }}</td><td>{{ $e['colonne'] ?? '' }}</td><td>{{ $e['message'] }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </section>

    <form method="POST" action="{{ route('admin.boutiques.grilles.update', $site) }}" class="bo-carte !mt-0" novalidate>
        @csrf @method('PUT')
        <p class="texte-carte max-w-3xl">{{ __('admin.grilles.formulaire_aide', [
            'caution' => $config['mois_caution'],
            'niveaux' => collect($config['mois_avance_par_niveau'])->map(fn ($m, $n) => $n.' '.$m)->implode(', '),
            'types' => collect($config['mois_avance_par_type'])->map(fn ($m, $t) => $t.' '.$m)->implode(', '),
        ]) }}</p>
        <p class="texte-carte mt-1 max-w-3xl">{{ $g['calcul'] }}</p>
        @if ($errors->any())
            <x-admin.message type="erreur" class="mt-3">{{ $errors->first() }}</x-admin.message>
        @endif

        <div class="mt-4 overflow-x-auto">
            <table class="bo-tableau">
                <thead><tr>
                    <th scope="col">{{ $g['niveau'] }}</th><th scope="col">{{ $g['type'] }}</th><th scope="col" class="montant">{{ $g['boutiques'] }}</th>
                    <th scope="col">{{ $g['unite'] }}</th><th scope="col">{{ $g['loyer_m2'] }}</th><th scope="col">{{ $g['mois_caution'] }}</th><th scope="col">{{ $g['mois_avance'] }}</th>
                </tr></thead>
                <tbody>
                    @forelse ($combinaisons as $c)
                        @php
                            $ligne = $lignes[GrilleTarifaire::cle($c['niveau']->code, $c['type']->id)] ?? null;
                            $propose = GrilleTarifaire::valeursProposees($c['niveau']->code, $c['type']);
                            $cle = 'lignes.'.$c['niveau']->code.'.'.$c['type']->id;
                            $nom = 'lignes['.$c['niveau']->code.']['.$c['type']->id.']';
                            $id = 'ligne-'.$c['niveau']->code.'-'.$c['type']->id;
                        @endphp
                        <tr>
                            <th scope="row" class="font-semibold">{{ $c['niveau']->label() }}</th>
                            <td>{{ $c['type']->code ? $c['type']->code.' · ' : '' }}{{ $c['type']->libelle }}</td>
                            <td class="montant">{{ format_nombre($c['nombre']) }}</td>
                            <td><label for="{{ $id }}-unite" class="sr-only">{{ $g['unite'] }} · {{ $c['niveau']->label() }}, {{ $c['type']->libelle }}</label>
                                <select id="{{ $id }}-unite" name="{{ $nom }}[unite_id]" class="champ !w-36 @error($cle.'.unite_id') est-invalide @enderror">
                                    {{-- Unités lues dans le référentiel « Unités » (validées et actives) ; l'unité déjà enregistrée reste proposée. --}}
                                    @php($choisie = (int) old($cle.'.unite_id', $ligne?->unite_id ?? $propose['unite_id']))
                                    @foreach ($unitesGrille->union($ligne?->unite ? [$ligne->unite_id => $ligne->unite->libelle] : []) as $valeur => $libelleUnite)
                                        <option value="{{ $valeur }}" @selected($choisie === (int) $valeur)>{{ $libelleUnite }}</option>
                                    @endforeach
                                </select></td>
                            <td><label for="{{ $id }}-loyer" class="sr-only">{{ $g['loyer_m2'] }} · {{ $c['niveau']->label() }}, {{ $c['type']->libelle }}</label>
                                <input id="{{ $id }}-loyer" name="{{ $nom }}[loyer_m2]" type="number" min="1" inputmode="numeric" class="champ !w-36 @error($cle.'.loyer_m2') est-invalide @enderror"
                                       value="{{ old($cle.'.loyer_m2', $ligne?->loyer_m2) }}"></td>
                            <td><label for="{{ $id }}-caution" class="sr-only">{{ $g['mois_caution'] }} · {{ $c['niveau']->label() }}, {{ $c['type']->libelle }}</label>
                                <input id="{{ $id }}-caution" name="{{ $nom }}[mois_caution]" type="number" min="0" inputmode="numeric" class="champ !w-24 @error($cle.'.mois_caution') est-invalide @enderror"
                                       value="{{ old($cle.'.mois_caution', $ligne?->mois_caution ?? $propose['mois_caution']) }}"></td>
                            <td><label for="{{ $id }}-avance" class="sr-only">{{ $g['mois_avance'] }} · {{ $c['niveau']->label() }}, {{ $c['type']->libelle }}</label>
                                <input id="{{ $id }}-avance" name="{{ $nom }}[mois_avance]" type="number" min="0" inputmode="numeric" class="champ !w-24 @error($cle.'.mois_avance') est-invalide @enderror"
                                       value="{{ old($cle.'.mois_avance', $ligne?->mois_avance ?? $propose['mois_avance']) }}"></td>
                        </tr>
                    @empty
                        <tr><td colspan="6">{{ $g['aucune_combinaison'] }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4 flex flex-wrap gap-3">
            <x-admin.enregistrer-soumettre />
            <x-admin.bouton variante="contour" href="{{ route('admin.boutiques.grilles.show', $site) }}">{{ __('admin.tranches.formulaire.annuler') }}</x-admin.bouton>
        </div>
    </form>
</x-admin.layout>
