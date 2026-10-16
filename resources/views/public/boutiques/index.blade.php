{{--
    Boutiques du marché, au premier arrivé : campagne, choix du site, du niveau et du bloc, puis plan schématique généré
    depuis la base (vrais codes, statut en couleur et en toutes lettres, légende). Aucune donnée personnelle : seul le
    statut de chaque boutique est public. Une boutique disponible mène à son récapitulatif et à la réservation.
--}}
@php
    $b = __('public.boutiques');
    $p = $b['plan'];
    $titre = $b['titre'];
    $lien = fn (array $choix) => route('boutiques', array_filter(['site' => $site?->id, 'niveau' => $niveau, 'bloc' => $bloc, ...$choix], fn ($v) => $v !== null));
@endphp

<x-public.layout :titre="$titre">
    <x-public.page-entete :titre="$titre" :intro="$b['intro']" :fil="[['libelle' => $titre]]" />

    <div class="conteneur py-8 tab:py-12">
        <x-public.alerte variante="attention" :titre="$b['independance_titre']" class="mb-8">{{ $b['independance'] }}</x-public.alerte>

        @if (! $campagne)
            <x-etat-vide icone="boutiques" :titre="$b['vide_titre']" :lien="route('faq')" :libelle-lien="__('public.etats_vides.liens.faq')">{{ $b['vide'] }}</x-etat-vide>
        @else
            <section aria-labelledby="campagne-titre">
                <h2 id="campagne-titre">{{ $campagne->nom }}</h2>
                <p class="mt-1 font-semibold text-vert-fonce">{{ __('public.boutiques.periode', ['debut' => $campagne->date_ouverture->format('d/m/Y à H\hi'), 'fin' => $campagne->date_cloture->format('d/m/Y à H\hi')]) }}</p>
                <p class="mt-2">{{ $b['premier_arrive'] }} {{ __('public.boutiques.plafond', ['n' => $campagne->max_boutiques_par_personne]) }}</p>
                @if ($campagne->description)
                    <p class="mt-3 max-w-3xl whitespace-pre-line leading-relaxed">{{ $campagne->description }}</p>
                @endif
                <a href="{{ route('boutiques.reglement') }}" class="lien-fort mt-2 inline-block">{{ $b['lire_reglement'] }}</a>
                @if (! $ouverte)
                    <x-public.alerte variante="info" class="mt-4" data-campagne-fermee>
                        {{ $campagne->date_ouverture->isFuture() ? __('public.boutiques.a_venir', ['date' => $campagne->date_ouverture->translatedFormat('j F Y à H\hi')]) : $b['fermee'] }}
                    </x-public.alerte>
                @endif
            </section>

            @if ($mesReservations->isNotEmpty())
                <section class="carte mt-6 p-5" aria-labelledby="mes-reservations-titre">
                    <h2 id="mes-reservations-titre" class="!text-lg">{{ $b['mes_reservations'] }}</h2>
                    <ul class="mt-2 space-y-1">
                        @foreach ($mesReservations as $r)
                            <li><a href="{{ route('boutiques.reservation.show', $r->reference) }}" class="lien-fort">{{ $r->boutique?->code }}</a> · {{ $r->statut->label() }}</li>
                        @endforeach
                    </ul>
                </section>
            @endif

            <section class="mt-8" aria-labelledby="comment-titre">
                <h2 id="comment-titre" class="!text-xl">{{ $b['comment_titre'] }}</h2>
                <ol class="mt-4 grid gap-3 tab:grid-cols-2 lg:grid-cols-4">
                    @foreach ($b['etapes'] as $i => $etape)
                        <li class="carte p-4">
                            <span class="flex h-8 w-8 items-center justify-center rounded-full bg-vert-fonce font-bold text-white" aria-hidden="true">{{ $i + 1 }}</span>
                            <p class="mt-2 font-bold text-encre">{{ $etape['titre'] }}</p>
                            <p class="texte-carte">{{ $etape['texte'] }}</p>
                        </li>
                    @endforeach
                </ol>
            </section>

            <section class="mt-10" aria-labelledby="plan-titre" data-plan>
                <h2 id="plan-titre" class="!text-xl">{{ $p['titre'] }}</h2>

                @foreach ([['site', $p['site'], $sites->mapWithKeys(fn ($s) => [$s->id => ($s->code ? $s->code.' · ' : '').$s->nom]), $site?->id],
                           ['niveau', $p['niveau'], $niveaux->keys()->mapWithKeys(fn ($n) => [$n => $n]), $niveau],
                           ['bloc', $p['bloc'], collect($blocs)->mapWithKeys(fn ($bl) => [$bl => $bl]), $bloc]] as [$cle, $libelle, $options, $actuel])
                    @if ($options->isNotEmpty())
                        <nav class="plan-choix mt-4" aria-label="{{ $libelle }}">
                            <span class="plan-choix-libelle">{{ $libelle }}</span>
                            @foreach ($options as $valeur => $texte)
                                <a href="{{ $lien($cle === 'site' ? ['site' => $valeur, 'niveau' => null, 'bloc' => null] : ($cle === 'niveau' ? ['niveau' => $valeur, 'bloc' => null] : ['bloc' => $valeur])) }}"
                                   @class(['plan-choix-option', 'est-actif' => (string) $valeur === (string) $actuel]) @if ((string) $valeur === (string) $actuel) aria-current="page" @endif>{{ $texte }}</a>
                            @endforeach
                        </nav>
                    @endif
                @endforeach

                <ul class="plan-legende mt-5" aria-label="{{ $p['legende'] }}">
                    @foreach (['disponible', 'pre_reservee', 'reservee', 'attribuee'] as $statut)
                        <li><span class="plan-pastille plan-{{ $statut }}" aria-hidden="true"></span>{{ $p['statuts'][$statut] }}</li>
                    @endforeach
                </ul>

                @if ($plan->isEmpty())
                    <p class="texte-carte mt-4">{{ $p['aucune'] }}</p>
                @else
                    <ul class="plan-boutiques mt-4" data-bloc="{{ $niveau }}-{{ $bloc }}">
                        @foreach ($plan as $boutique)
                            @php($statut = $boutique->statut->surPlan())
                            <li>
                                @if ($statut === 'disponible' && $ouverte)
                                    <a href="{{ route('boutiques.boutique', $boutique->code) }}" class="plan-case plan-{{ $statut }}" data-statut="{{ $statut }}"
                                       aria-label="{{ __('public.boutiques.plan.choisir', ['code' => $boutique->code]) }}">
                                        <span class="plan-code">{{ $boutique->code }}</span>
                                        <span class="plan-etat">{{ $p['statuts'][$statut] }}</span>
                                        @if ($boutique->surface)<span class="plan-surface">{{ __('public.boutiques.plan.surface', ['surface' => format_nombre((float) $boutique->surface, 1)]) }}</span>@endif
                                    </a>
                                @else
                                    <span class="plan-case plan-{{ $statut }}" data-statut="{{ $statut }}"
                                          aria-label="{{ __('public.boutiques.plan.boutique', ['code' => $boutique->code, 'statut' => $p['statuts'][$statut]]) }}">
                                        <span class="plan-code">{{ $boutique->code }}</span>
                                        <span class="plan-etat">{{ $p['statuts'][$statut] }}</span>
                                        @if ($boutique->surface)<span class="plan-surface">{{ __('public.boutiques.plan.surface', ['surface' => format_nombre((float) $boutique->surface, 1)]) }}</span>@endif
                                    </span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endif
    </div>
</x-public.layout>
