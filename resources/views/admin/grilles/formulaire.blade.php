@php
    use App\Models\GrilleTarifaire;

    $g = __('admin.grilles');
    $config = config('raaga.grille_tarifaire');
    $titre = $g['modifier'].' — '.$site->nom;
@endphp

<x-admin.layout :titre="$titre" :fil="[['libelle' => __('admin.boutiques.titre'), 'url' => route('admin.boutiques')], ['libelle' => $g['titre'], 'url' => route('admin.boutiques.grilles')], ['libelle' => $site->nom, 'url' => route('admin.boutiques.grilles.show', $site)], ['libelle' => $g['modifier']]]">
    <form method="POST" action="{{ route('admin.boutiques.grilles.update', $site) }}" class="bo-carte !mt-0" novalidate>
        @csrf @method('PUT')
        <p class="texte-carte max-w-3xl">{{ __('admin.grilles.formulaire_aide', [
            'caution' => $config['mois_caution'],
            'niveaux' => collect($config['mois_avance_par_niveau'])->map(fn ($m, $n) => $n.' '.$m)->implode(', '),
            'types' => collect($config['mois_avance_par_type'])->map(fn ($m, $t) => $t.' '.$m)->implode(', '),
        ]) }}</p>
        <p class="texte-carte mt-1 max-w-3xl">{{ $g['calcul'] }}</p>
        @if ($errors->any())
            <p class="bo-message bo-message-erreur mt-3" role="alert">{{ $errors->first() }}</p>
        @endif

        <div class="mt-4 overflow-x-auto">
            <table class="bo-tableau">
                <thead><tr>
                    <th scope="col">{{ $g['niveau'] }}</th><th scope="col">{{ $g['type'] }}</th><th scope="col" class="montant">{{ $g['boutiques'] }}</th>
                    <th scope="col">{{ $g['loyer_m2'] }}</th><th scope="col">{{ $g['mois_caution'] }}</th><th scope="col">{{ $g['mois_avance'] }}</th>
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
                            <td>{{ $c['type']->code ? $c['type']->code.' · ' : '' }}{{ $c['type']->libelle }}<br><span class="texte-carte">{{ $c['type']->unite_loyer->label() }}</span></td>
                            <td class="montant">{{ format_nombre($c['nombre']) }}</td>
                            <td><label for="{{ $id }}-loyer" class="sr-only">{{ $g['loyer_m2'] }} — {{ $c['niveau']->label() }}, {{ $c['type']->libelle }}</label>
                                <input id="{{ $id }}-loyer" name="{{ $nom }}[loyer_m2]" type="number" min="1" inputmode="numeric" class="champ !w-36 @error($cle.'.loyer_m2') est-invalide @enderror"
                                       value="{{ old($cle.'.loyer_m2', $ligne?->loyer_m2) }}"></td>
                            <td><label for="{{ $id }}-caution" class="sr-only">{{ $g['mois_caution'] }} — {{ $c['niveau']->label() }}, {{ $c['type']->libelle }}</label>
                                <input id="{{ $id }}-caution" name="{{ $nom }}[mois_caution]" type="number" min="0" inputmode="numeric" class="champ !w-24 @error($cle.'.mois_caution') est-invalide @enderror"
                                       value="{{ old($cle.'.mois_caution', $ligne?->mois_caution ?? $propose['mois_caution']) }}"></td>
                            <td><label for="{{ $id }}-avance" class="sr-only">{{ $g['mois_avance'] }} — {{ $c['niveau']->label() }}, {{ $c['type']->libelle }}</label>
                                <input id="{{ $id }}-avance" name="{{ $nom }}[mois_avance]" type="number" min="0" inputmode="numeric" class="champ !w-24 @error($cle.'.mois_avance') est-invalide @enderror"
                                       value="{{ old($cle.'.mois_avance', $ligne?->mois_avance ?? $propose['mois_avance']) }}"></td>
                        </tr>
                    @empty
                        <tr><td colspan="6">{{ $g['aucune_combinaison'] }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4 flex gap-3">
            <button type="submit" class="bo-bouton bo-bouton-principal">{{ __('admin.tranches.formulaire.enregistrer') }}</button>
            <a href="{{ route('admin.boutiques.grilles.show', $site) }}" class="bo-bouton bo-bouton-secondaire">{{ __('admin.tranches.formulaire.annuler') }}</a>
        </div>
    </form>
</x-admin.layout>
