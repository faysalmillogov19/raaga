@php
    use App\Enums\Droit;

    $t = __('admin.tableau');
    $a = $t['a_traiter'];
    $n = count($aTraiter);
@endphp

<x-admin.layout :titre="$t['titre']" :sous-titre="trans_choice('admin.tableau.sous_titre', $n, ['n' => format_nombre($n)])">
    <x-slot:actions>
        @can(Droit::AuditVoir->value)
            <x-admin.bouton variante="contour" :href="route('admin.audit')">{{ __('admin.menu.audit') }}</x-admin.bouton>
        @endcan
        @can(Droit::KycTraiter->value)
            <x-admin.bouton :href="route('admin.kyc')">{{ $t['traiter_kyc'] }}</x-admin.bouton>
        @elsecan(Droit::SitesGerer->value)
            <x-admin.bouton icone="plus" :href="route('admin.referencement.boutiques.ajouter')">{{ __('admin.referencement.boutiques.ajouter') }}</x-admin.bouton>
        @endcan
    </x-slot:actions>

    {{-- Cartes chiffrées : seulement les indicateurs permis au profil. --}}
    @if ($cartes)
        <div class="adm-cartes">
            @foreach ($cartes as $carte)
                <a href="{{ $carte['url'] }}" class="adm-indicateur" data-indicateur="{{ $carte['cle'] }}">
                    <span class="adm-indicateur-libelle">{{ $carte['libelle'] }}</span>
                    <span class="adm-indicateur-valeur">{{ $carte['valeur'] }}</span>
                    <x-admin.badge-statut :statut="$carte['pastille']" :variante="$carte['variante']" />
                </a>
            @endforeach
        </div>
    @endif

    {{-- À traiter --}}
    <section class="bo-carte adm-carte-tableau" id="a-traiter" aria-labelledby="a-traiter-titre">
        <div class="adm-carte-entete">
            <h2 id="a-traiter-titre" class="adm-carte-titre">{{ $a['titre'] }}</h2>
            <span class="texte-carte text-[12px]">{{ $a['ordre'] }}</span>
        </div>
        @if ($aTraiter === [])
            <p class="texte-carte px-[22px] py-4">{{ $a['rien'] }}</p>
        @else
            <div class="bo-donnees-cadre !mx-0">
                <table class="bo-tableau bo-tableau-donnees">
                    <thead><tr>
                        @foreach ($a['colonnes'] as $cle => $libelle)<th scope="col" @class(['bo-actions' => $cle === 'action'])>{{ $libelle }}</th>@endforeach
                    </tr></thead>
                    <tbody>
                        @foreach ($aTraiter as $e)
                            <tr>
                                <td class="font-semibold">{{ $e['element'] }}</td>
                                <td>{{ $e['objet'] }}@if ($e['detail'])<br><span class="texte-carte text-[12px]">{{ __('admin.tableau.a_traiter.motif', ['motif' => $e['detail']]) }}</span>@endif</td>
                                <td class="texte-carte">{{ $e['par'] ?? '—' }}</td>
                                <td class="whitespace-nowrap texte-carte">{{ $e['date']?->format('d/m/Y H:i') ?? '—' }}</td>
                                <td><x-admin.badge-statut :statut="$e['statut']" /></td>
                                <td class="bo-actions"><x-admin.bouton variante="contour" taille="petit" :href="$e['url']" aria-label="{{ $a['examiner'] }} : {{ $e['element'] }} {{ $e['objet'] }}">{{ $a['examiner'] }}</x-admin.bouton></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{-- Avancement des sites : boutiques prévues et importées --}}
    @if ($avancement->isNotEmpty())
        @php($v = $t['avancement'])
        <section class="bo-carte" aria-labelledby="avancement-titre">
            <h2 id="avancement-titre" class="adm-carte-titre">{{ $v['titre'] }}</h2>
            <ul class="mt-4 space-y-4">
                @foreach ($avancement as $ligne)
                    @php($s = $ligne['site'])
                    <li>
                        <div class="flex items-center justify-between gap-3">
                            <span class="font-semibold">{{ ($s->code ? $s->code.' · ' : '').$s->nom }}</span>
                            <x-admin.badge-statut :statut="$s->statut" />
                        </div>
                        <div class="adm-barre" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $ligne['pourcent'] }}"
                             aria-label="{{ ($s->code ?? $s->nom) }} : {{ $ligne['pourcent'] }} %">
                            <span style="width: {{ $ligne['pourcent'] }}%"></span>
                        </div>
                        <p class="texte-carte text-[12px]">
                            @if ($ligne['prevu'] !== null)
                                {{ __('admin.tableau.avancement.ligne', ['reel' => format_nombre($ligne['reel']), 'prevu' => format_nombre($ligne['prevu'])]) }}
                                @if ($ligne['reel'] !== $ligne['prevu']) · {{ __('admin.tableau.avancement.ecart', ['ecart' => ($ligne['reel'] > $ligne['prevu'] ? '+' : '−').format_nombre(abs($ligne['reel'] - $ligne['prevu']))]) }}@endif
                            @else
                                {{ __('admin.tableau.avancement.sans_prevision', ['reel' => format_nombre($ligne['reel'])]) }}
                            @endif
                        </p>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- Derniers imports --}}
    @if ($imports)
        <section class="bo-carte" aria-labelledby="imports-titre">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                <h2 id="imports-titre" class="adm-carte-titre">{{ $t['imports']['titre'] }}</h2>
                <a href="{{ route('admin.referencement.imports') }}" class="font-semibold text-vert-fonce">{{ $t['imports']['tous'] }}</a>
            </div>
            <x-tableau :t="$imports" :libelle="$t['imports']['titre']" />
        </section>
    @endif
</x-admin.layout>
