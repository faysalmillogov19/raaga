{{--
    Résultats d'un tableau de données : rechargés seuls (en-tête X-Tableau) après une recherche, un tri ou un changement de page.
    Soumission groupée ($t->selection) : filtre rapide « À soumettre (n) », case sur les lignes à soumettre (brouillon ou
    rejeté) et sur ses propres lignes soumises (retrait) ; « tout cocher » limité aux lignes à soumettre de la page.
--}}
@php
    $l = __('admin.donnees');
    $s = __('admin.soumission');
    $selection = $t->selection;
    $etats = $selection ? $t->lignes->mapWithKeys(fn ($ligne) => [$ligne->getKey() => \App\Services\Validation\SoumissionGroupee::etat($selection, $ligne, auth()->user())]) : collect();
    $filtreActif = isset($t->etat['filtres']['a_soumettre']);
@endphp
<div data-tableau-resultats data-export="{{ $t->exportable ? $t->urlExport() : '' }}">
    @if ($selection)
        <div class="mb-3 flex flex-wrap gap-2" data-filtre-rapide>
            <a href="{{ $t->url(['a_soumettre' => $filtreActif ? null : '1', 'page' => '']) }}" @class(['adm-filtre-rapide', 'adm-filtre-rapide-actif' => $filtreActif])
               @if ($filtreActif) aria-current="true" @endif>{{ __('admin.soumission.a_soumettre', ['n' => format_nombre($t->aSoumettre)]) }}</a>
        </div>
    @endif

    @if ($t->exportable && ! $t->exportEnTete && $t->filtrees > 0)
        <div class="mb-3 flex justify-end">
            <x-admin.bouton variante="contour" icone="exporter" :href="$t->urlExport()" title="{{ $l['exporter_aide'] }}">{{ $l['exporter'] }}</x-admin.bouton>
        </div>
    @endif

    @if ($t->lignes->isEmpty())
        <x-etat-vide icone="info" :titre="$l['vide_titre']" :lien="$t->estFiltre() ? $t->urlInitiale() : null" :libelle-lien="$l['effacer']">
            {{ $t->estFiltre() || $t->total > 0 ? $l['vide'] : $l['vide_liste'] }}
        </x-etat-vide>
    @else
        <div class="bo-donnees-cadre">
            <table class="bo-tableau bo-tableau-donnees">
                <thead>
                    <tr>
                        @if ($selection)
                            <th scope="col" class="w-10">
                                @if ($etats->contains('soumettre'))
                                    <input type="checkbox" data-tout-cocher aria-label="{{ $s['tout_cocher'] }}" @change="toutCocher($event.target.checked)">
                                @else
                                    <span class="sr-only">{{ $s['colonne'] }}</span>
                                @endif
                            </th>
                        @endif
                        @foreach ($t->colonnes as $c)
                            <th scope="col" class="{{ $c->classes() }}" @if ($c->estTriable()) aria-sort="{{ $t->ariaSort($c) }}" @endif>
                                @if ($c->estTriable())
                                    @php($sens = $t->ariaSort($c))
                                    <a href="{{ $t->urlTri($c) }}" data-tableau-lien class="bo-tri" aria-label="{{ __('admin.donnees.trier', ['colonne' => $c->libelle]) }}{{ $sens === 'none' ? '' : ' ('.$l[$sens === 'ascending' ? 'croissant' : 'decroissant'].')' }}">
                                        <span>{{ $c->libelle }}</span>
                                        <span class="bo-tri-fleche" aria-hidden="true">{{ $sens === 'ascending' ? '▲' : ($sens === 'descending' ? '▼' : '⇅') }}</span>
                                    </a>
                                @else
                                    {{ $c->libelle }}
                                @endif
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($t->lignes as $ligne)
                        <tr>
                            @if ($selection)
                                <td class="w-10" data-selection>
                                    @if ($etat = $etats[$ligne->getKey()] ?? null)
                                        @php($libelleLigne = \App\Services\Validation\SoumissionGroupee::libelle($selection, $ligne))
                                        <input type="checkbox" name="elements[]" value="{{ $selection }}:{{ $ligne->getKey() }}" data-etat="{{ $etat }}"
                                               data-libelle="{{ $libelleLigne }}" aria-label="{{ __('admin.soumission.cocher', ['libelle' => $libelleLigne]) }}" @change="compter()">
                                        @if ($etat === 'retirer')
                                            <form method="POST" action="{{ route('admin.soumission.retirer') }}" class="mt-1">
                                                @csrf
                                                <input type="hidden" name="elements[]" value="{{ $selection }}:{{ $ligne->getKey() }}">
                                                <button type="submit" class="text-sm font-semibold text-vert-fonce underline underline-offset-2" data-retirer-ligne>{{ $s['retirer_ligne'] }}</button>
                                            </form>
                                        @endif
                                    @endif
                                </td>
                            @endif
                            @foreach ($t->colonnes as $c)
                                <td class="{{ $c->classes() }}" data-libelle="{{ $c->libelle }}">{{ $c->rendre($ligne) }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div class="bo-donnees-pied">
        <p class="texte-carte" role="status" aria-live="polite">
            {{ __($t->estFiltre() ? 'admin.donnees.info_filtre' : 'admin.donnees.info', ['de' => format_nombre($t->premiereLigne()), 'a' => format_nombre($t->derniereLigne()),
                'n' => format_nombre($t->filtrees), 'total' => format_nombre($t->total), 'unite' => $t->unite ?? $l['lignes']]) }}
        </p>
        <x-admin.pagination :page="$t->page" :derniere="$t->derniere()" :url="fn (int $p) => $t->urlPage($p)" lien="data-tableau-lien" />
    </div>
</div>
