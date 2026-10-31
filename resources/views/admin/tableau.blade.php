@php
    use App\Enums\Droit;

    $t = __('admin.tableau');
    $a = $t['a_traiter'];
    $n = count($aTraiter);
@endphp

<x-admin.layout :titre="$t['titre']" :sous-titre="trans_choice('admin.tableau.sous_titre', $n, ['n' => format_nombre($n)])">
    <x-slot:actions>
        @if (! \App\Support\MenuBackoffice::enPreparation('audit') && auth()->user()->can(Droit::AuditVoir->value))
            <x-admin.bouton variante="contour" :href="route('admin.audit')">{{ __('admin.menu.audit') }}</x-admin.bouton>
        @endif
        @can(Droit::KycTraiter->value)
            <x-admin.bouton :href="route('admin.kyc')">{{ $t['traiter_kyc'] }}</x-admin.bouton>
        @elsecan(Droit::SitesGerer->value)
            <x-admin.bouton icone="plus" :href="route('admin.referencement.boutiques', ['ajouter' => 1])">{{ __('admin.referencement.boutiques.ajouter') }}</x-admin.bouton>
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

    {{-- SUPER_ADMIN et agent de guichet : cartes dont les fichiers n'ont pas pu être générés (trois essais), avec relance. --}}
    @can(Droit::CartesFichiersRelancer->value)
        @php($cartesEnEchec = \App\Services\Cartes\FichiersCarte::enEchec())
        @if ($cartesEnEchec->isNotEmpty())
            @php($e = __('admin.cartes.echecs'))
            <section class="bo-carte mb-4" aria-labelledby="cartes-echec-titre" role="alert" data-cartes-echec>
                <h2 id="cartes-echec-titre" class="!text-[1.05rem]">{{ trans_choice('admin.cartes.echecs.titre', $cartesEnEchec->count(), ['n' => format_nombre($cartesEnEchec->count())]) }}</h2>
                <p class="texte-carte mt-1">{{ $e['intro'] }}</p>
                <div class="mt-3 overflow-x-auto">
                    <table class="bo-tableau">
                        <thead><tr>@foreach ($e['colonnes'] as $colonne)<th scope="col">{{ $colonne }}</th>@endforeach</tr></thead>
                        <tbody>
                            @foreach ($cartesEnEchec as $carteEchec)
                                <tr data-carte-echec="{{ $carteEchec->numero }}">
                                    <td class="font-semibold">{{ $carteEchec->numero }}</td>
                                    <td>{{ $carteEchec->contenu['raison_sociale'] ?? $carteEchec->contenu['nom'] ?? '' }}</td>
                                    <td>{{ $carteEchec->fichiers_echec_le->format('d/m/Y à H:i') }}</td>
                                    <td>{{ $carteEchec->fichiers_echec_cause }}</td>
                                    <td>
                                        <form method="POST" action="{{ route('admin.cartes.fichiers.relancer', $carteEchec) }}">
                                            @csrf
                                            <x-admin.bouton type="submit" taille="petit" variante="contour">{{ $e['relancer'] }}</x-admin.bouton>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    @endcan

    {{-- GESTIONNAIRE et RÉGULATEUR : brouillons et rejetés non soumis au VALIDATEUR. --}}
    @can(Droit::BrouillonsVoir->value)
        @php($brouillons = \App\Services\Validation\SoumissionGroupee::nombreBrouillons(auth()->user()))
        @if ($brouillons > 0)
            <p class="bo-carte font-semibold" data-brouillons>
                <a href="{{ route('admin.brouillons') }}" class="text-vert-fonce underline underline-offset-2">{{ trans_choice('admin.soumission.brouillons.non_soumis', $brouillons, ['n' => format_nombre($brouillons)]) }}</a>
            </p>
        @endif
    @endcan

    {{-- RAAGA Invest (RÉGULATEUR, ADMIN) : manifestations d'intérêt, total des intentions, montant réellement collecté. --}}
    @can(Droit::InteretsVoir->value)
        <section aria-label="{{ __('admin.interets.titre') }}" class="mb-4">
            @include('admin.interets._indicateurs', ['indicateurs' => \App\Services\Invest\RaagaInvest::indicateurs()])
        </section>
    @endcan

    {{-- Réservation des boutiques (RÉGULATEUR, VALIDATEUR) : par campagne, boutiques disponibles, pré-réservées, réservées et attribuées. --}}
    @can(Droit::BoutiquesVoir->value)
        @php($campagnesBoutiques = \App\Models\CampagneBoutiques::query()->whereIn('statut', ['OUVERTE', 'CONFIRMEE', 'CLOTUREE'])->latest('date_ouverture')->limit(3)->get())
        @if ($campagnesBoutiques->isNotEmpty())
            <section class="bo-carte mb-4" aria-labelledby="campagnes-boutiques-titre" data-campagnes-boutiques>
                <h2 id="campagnes-boutiques-titre" class="!text-[1.05rem]">{{ __('admin.tableau.campagnes_boutiques') }}</h2>
                <div class="mt-3 overflow-x-auto">
                    <table class="bo-tableau">
                        <thead><tr><th scope="col">{{ __('admin.boutiques.colonnes.nom') }}</th>
                            @foreach (['disponible', 'pre_reservee', 'reservee', 'attribuee'] as $statut)<th scope="col" class="text-right">{{ __('public.boutiques.plan.statuts.'.$statut) }}</th>@endforeach</tr></thead>
                        <tbody>
                            @foreach ($campagnesBoutiques as $campagne)
                                @php($n = $campagne->compteurs())
                                <tr><td><a href="{{ route('admin.boutiques.show', $campagne) }}" class="font-semibold">{{ $campagne->nom }}</a> <span class="texte-carte">· {{ $campagne->statut->label() }}</span></td>
                                    @foreach (['disponible', 'pre_reservee', 'reservee', 'attribuee'] as $statut)<td class="text-right" data-statut-boutiques="{{ $statut }}">{{ format_nombre($n[$statut]) }}</td>@endforeach</tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    @endcan

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
