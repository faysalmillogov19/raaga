{{-- Résultats d'un tableau de données : rechargés seuls (en-tête X-Tableau) après une recherche, un tri ou un changement de page. --}}
@php($l = __('admin.donnees'))
<div data-tableau-resultats>
    @if ($t->exportable && $t->filtrees > 0)
        <div class="mb-2 flex justify-end">
            <a href="{{ $t->urlExport() }}" class="bo-bouton bo-bouton-secondaire" title="{{ $l['exporter_aide'] }}">{{ $l['exporter'] }}</a>
        </div>
    @endif

    @if ($t->lignes->isEmpty())
        <x-etat-vide icone="info" :titre="$l['vide_titre']" :lien="$t->estFiltre() ? request()->url() : null" :libelle-lien="$l['effacer']">
            {{ $t->estFiltre() || $t->total > 0 ? $l['vide'] : $l['vide_liste'] }}
        </x-etat-vide>
    @else
        <div class="bo-donnees-cadre">
            <table class="bo-tableau bo-tableau-donnees">
                <thead>
                    <tr>
                        @foreach ($t->colonnes as $c)
                            <th scope="col" class="{{ $c->classes() }}" @if ($c->estTriable()) aria-sort="{{ $t->ariaSort($c) }}" @endif>
                                @if ($c->estTriable())
                                    @php($sens = $t->ariaSort($c))
                                    <a href="{{ $t->urlTri($c) }}" data-tableau-lien class="bo-tri" aria-label="{{ __('admin.donnees.trier', ['colonne' => $c->libelle]) }}{{ $sens === 'none' ? '' : ' ('.$l[$sens === 'ascending' ? 'croissant' : 'decroissant'].')' }}">
                                        <span>{{ $c->libelle }}</span>
                                        <span class="bo-tri-fleche" aria-hidden="true">{{ $sens === 'ascending' ? '▲' : ($sens === 'descending' ? '▼' : '↕') }}</span>
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
                'n' => format_nombre($t->filtrees), 'total' => format_nombre($t->total)]) }}
        </p>
        @if ($t->derniere() > 1)
            <nav aria-label="{{ $l['pagination'] }}">
                <ul class="bo-pagination">
                    <li>
                        @if ($t->page > 1)
                            <a href="{{ $t->urlPage($t->page - 1) }}" data-tableau-lien rel="prev">{{ $l['precedent'] }}</a>
                        @else
                            <span aria-disabled="true">{{ $l['precedent'] }}</span>
                        @endif
                    </li>
                    @foreach ($t->pages() as $p)
                        <li>
                            @if ($p === null)
                                <span aria-hidden="true">…</span>
                            @elseif ($p === $t->page)
                                <a href="{{ $t->urlPage($p) }}" data-tableau-lien aria-current="page" aria-label="{{ __('admin.donnees.page', ['n' => $p]) }}">{{ $p }}</a>
                            @else
                                <a href="{{ $t->urlPage($p) }}" data-tableau-lien aria-label="{{ __('admin.donnees.page', ['n' => $p]) }}">{{ $p }}</a>
                            @endif
                        </li>
                    @endforeach
                    <li>
                        @if ($t->page < $t->derniere())
                            <a href="{{ $t->urlPage($t->page + 1) }}" data-tableau-lien rel="next">{{ $l['suivant'] }}</a>
                        @else
                            <span aria-disabled="true">{{ $l['suivant'] }}</span>
                        @endif
                    </li>
                </ul>
            </nav>
        @endif
    </div>
</div>
