@php
    use App\Enums\Droit;
    use App\Enums\StatutGrilleTarifaire;
    use App\Models\GrilleTarifaire;

    $g = __('admin.grilles');
    $saisir = auth()->user()->can(Droit::BoutiquesSaisir->value);
    $titre = $g['titre'].' — '.$site->nom;
@endphp

<x-admin.layout :titre="$titre" :fil="[['libelle' => __('admin.boutiques.titre'), 'url' => route('admin.boutiques')], ['libelle' => $g['titre'], 'url' => route('admin.boutiques.grilles')], ['libelle' => $site->nom]]">
    <section class="bo-carte !mt-0" aria-labelledby="etat-titre">
        <h2 id="etat-titre" class="!text-[1.15rem]">{{ __('admin.parametrage.statut') }} :
            @if ($grille)@include('admin.parametrage._statut', ['statut' => $grille->statut])@else{{ $g['aucune_grille'] }}@endif</h2>
        <p class="texte-carte mt-1">{{ $confirmee ? __('admin.grilles.en_vigueur', ['n' => $confirmee['version']]) : $g['aucune_confirmee'] }}</p>
        <p class="texte-carte mt-1">{{ $g['calcul'] }}</p>
        @if ($grille?->motif_rejet && $grille->statut === StatutGrilleTarifaire::Brouillon)
            <p class="bo-message bo-message-erreur mt-4 !mb-0" role="alert">{{ __('admin.parametrage.motif_rejet', ['motif' => $grille->motif_rejet]) }}</p>
        @endif

        <div class="mt-4 flex flex-wrap gap-3">
            @if ($saisir && (! $grille || $grille->estModifiable()))
                <a href="{{ route('admin.boutiques.grilles.edit', $site) }}" class="bo-bouton bo-bouton-secondaire">{{ $g['modifier'] }}</a>
                @if ($grille)
                    <x-admin.confirmation id="soumettre" :titre="__('admin.parametrage.actions.soumettre')" :message="__('admin.parametrage.confirmations.soumettre')"
                                          :action="route('admin.boutiques.grilles.soumettre', $site)" :bouton="__('admin.parametrage.actions.soumettre')">
                        <x-slot:declencheur>{{ __('admin.parametrage.actions.soumettre') }}</x-slot:declencheur>
                    </x-admin.confirmation>
                @endif
            @elseif ($saisir && $grille?->statut === StatutGrilleTarifaire::Confirme)
                <x-admin.confirmation id="reviser" :titre="$g['actions']['reviser']" :message="$g['confirmations']['reviser']"
                                      :action="route('admin.boutiques.grilles.reviser', $site)" :bouton="$g['actions']['reviser']">
                    <x-slot:declencheur>{{ $g['actions']['reviser'] }}</x-slot:declencheur>
                </x-admin.confirmation>
            @endif

            @can(Droit::BoutiquesConfirmer->value)
                @if ($grille?->estSoumis())
                    <x-admin.confirmation id="confirmer" :titre="__('admin.parametrage.actions.confirmer')" :message="__('admin.parametrage.confirmations.confirmer')"
                                          :action="route('admin.boutiques.grilles.confirmer', $site)" :bouton="__('admin.parametrage.actions.confirmer')">
                        <x-slot:declencheur>{{ __('admin.parametrage.actions.confirmer') }}</x-slot:declencheur>
                    </x-admin.confirmation>
                    <x-admin.confirmation id="rejeter" :titre="__('admin.parametrage.actions.rejeter')" :message="__('admin.parametrage.confirmations.rejeter')"
                                          :action="route('admin.boutiques.grilles.rejeter', $site)" :bouton="__('admin.parametrage.actions.rejeter')" motif danger>
                        <x-slot:declencheur>{{ __('admin.parametrage.actions.rejeter') }}</x-slot:declencheur>
                    </x-admin.confirmation>
                @endif
            @endcan
        </div>
        @error('motif') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
    </section>

    @if ($grille?->estSoumis())
        <section class="bo-carte" aria-labelledby="diff-titre">
            <h2 id="diff-titre" class="!text-[1.15rem]">{{ __('admin.parametrage.differentiel') }}</h2>
            <div class="mt-2"><x-admin.differentiel :differentiel="$differentiel" :libelles="$g['champs']" :premiere="$premiereVersion" /></div>
        </section>
    @endif

    {{-- Lignes en cours (brouillon, soumises ou confirmées) et montants calculés par surface --}}
    <section class="bo-carte" aria-labelledby="lignes-titre">
        <h2 id="lignes-titre" class="!text-[1.15rem]">{{ $g['titre'] }}</h2>
        <div class="mt-3 overflow-x-auto">
            <table class="bo-tableau">
                <thead><tr>
                    <th scope="col">{{ $g['niveau'] }}</th><th scope="col">{{ $g['type'] }}</th><th scope="col" class="montant">{{ $g['boutiques'] }}</th>
                    <th scope="col" class="montant">{{ $g['loyer_m2'] }}</th><th scope="col" class="montant">{{ $g['mois_caution'] }}</th><th scope="col" class="montant">{{ $g['mois_avance'] }}</th>
                    <th scope="col">{{ $g['exemples'] }}</th>
                </tr></thead>
                <tbody>
                    @forelse ($combinaisons as $c)
                        @php($ligne = $lignes[GrilleTarifaire::cle($c['niveau']->code, $c['type']->id)] ?? null)
                        <tr>
                            <td>{{ $c['niveau']->label() }}</td>
                            <td>{{ $c['type']->code ? $c['type']->code.' · ' : '' }}{{ $c['type']->libelle }}<br><span class="texte-carte">{{ $c['type']->unite_loyer->label() }}</span></td>
                            <td class="montant">{{ format_nombre($c['nombre']) }}</td>
                            @if ($ligne)
                                <td class="montant">{{ format_fcfa($ligne->loyer_m2) }}</td>
                                <td class="montant">{{ format_nombre($ligne->mois_caution) }}</td>
                                <td class="montant">{{ format_nombre($ligne->mois_avance) }}</td>
                                <td><ul class="space-y-1 text-sm">
                                    @foreach ($c['surfaces'] as $surface => $n)
                                        @if ($surface === '')
                                            <li class="font-semibold text-rouge">{{ __('admin.grilles.surface_manquante', ['n' => $n]) }}</li>
                                        @else
                                            @php($m = GrilleTarifaire::montants((float) $surface, $ligne->loyer_m2, $ligne->mois_caution, $ligne->mois_avance, $c['type']->unite_loyer))
                                            <li>{{ __('admin.grilles.exemple', ['surface' => format_nombre((float) $surface, 2), 'n' => $n,
                                                'loyer' => format_fcfa($m['loyer_mensuel']), 'caution' => format_fcfa($m['caution']), 'avance' => format_fcfa($m['avance'])]) }}</li>
                                        @endif
                                    @endforeach
                                </ul></td>
                            @else
                                <td colspan="4" class="font-semibold text-rouge">{{ $g['pas_de_ligne'] }}</td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="7">{{ $g['aucune_combinaison'] }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    @if ($grille)
        @include('admin.parametrage._historique', ['versions' => $versions, 'decisions' => $decisions])
    @endif
</x-admin.layout>
