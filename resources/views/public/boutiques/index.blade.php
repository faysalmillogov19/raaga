{{--
    Boutiques du marché, au premier arrivé. En tête : résumé de la campagne (état avec compte à rebours avant l'ouverture,
    dates, limite par personne en clair), puis directement le plan ; en bas, « Tout savoir » en sections repliables
    (présentation mise en forme, étapes, indépendance avec les actions). Plan : choix du site, du niveau et du bloc, plan schématique généré
    depuis la base (vrais codes, statut en couleur et en toutes lettres, sigle du type, légende), filtre par type. Détail
    de chaque emplacement au survol ou au clic (type, surface, observations, montants). Aucune donnée personnelle : seul
    le statut de chaque boutique est public. Une boutique disponible mène à son récapitulatif et à la réservation.
--}}
@php
    $b = __('public.boutiques');
    $p = $b['plan'];
    $titre = $b['titre'];
    $lien = fn (array $choix) => route('boutiques', array_filter(['site' => $site?->id, 'type' => $type?->id, 'niveau' => $niveau, 'bloc' => $bloc, ...$choix], fn ($v) => $v !== null));
@endphp

<x-public.layout :titre="$titre">
    <x-public.page-entete :titre="$titre" :intro="$b['intro']" :fil="[['libelle' => $titre]]" />

    <div class="conteneur py-8 tab:py-12">
        @if (! $campagne)
            <x-etat-vide icone="boutiques" :titre="$b['vide_titre']" :lien="route('faq')" :libelle-lien="__('public.etats_vides.liens.faq')">{{ $b['vide'] }}</x-etat-vide>
        @else
            @php
                $etat = $campagne->etatPublic();
            @endphp
            {{-- Résumé : la campagne, son état (avec compte à rebours avant l'ouverture), les dates, la limite par personne. --}}
            <section class="boutiques-resume carte" aria-labelledby="campagne-titre" data-etat-campagne="{{ $etat }}">
                <div class="boutiques-resume-entete">
                    <h2 id="campagne-titre" class="!text-xl">{{ $campagne->nom }}</h2>
                    <span class="boutiques-etat boutiques-etat-{{ $etat }}">{{ $b['etats'][$etat]['badge'] }}</span>
                </div>
                @if ($etat === 'bientot')
                    <div class="boutiques-annonce" data-campagne-fermee>
                        <p class="font-bold text-encre">{{ $b['etats']['bientot']['titre'] }}</p>
                        @if ($campagne->date_ouverture?->isFuture())
                            <p x-data="compteARebours(@js($campagne->date_ouverture->toIso8601String()), @js($b['compte_a_rebours']))" data-compte-a-rebours>
                                {{ __('public.boutiques.etats.bientot.le', ['date' => $campagne->date_ouverture->translatedFormat('j F Y à H\hi')]) }}
                                <span x-show="texte" x-cloak> · <strong x-text="texte"></strong></span>
                            </p>
                            <p class="texte-carte">{{ $b['etats']['bientot']['plan'] }}</p>
                        @else
                            <p>{{ $b['etats']['bientot']['date_a_venir'] }}</p>
                        @endif
                    </div>
                @elseif ($etat === 'ouverte')
                    <p class="boutiques-annonce boutiques-annonce-ouverte" data-campagne-ouverte>
                        {{ __('public.boutiques.etats.ouverte.texte', ['fin' => $campagne->date_cloture->translatedFormat('j F Y à H\hi')]) }}
                    </p>
                @else
                    <p class="boutiques-annonce boutiques-annonce-terminee" data-campagne-fermee>{{ $b['etats']['terminee']['texte'] }}</p>
                @endif
                <ul class="boutiques-faits">
                    <li>{{ __('public.boutiques.periode', ['debut' => $campagne->date_ouverture->format('d/m/Y à H\hi'), 'fin' => $campagne->date_cloture->format('d/m/Y à H\hi')]) }}</li>
                    <li>{{ $b['premier_arrive'] }}</li>
                    <li class="font-semibold text-encre" data-limites>{{ $limites['resume'] }}</li>
                    <li>{{ $b['independance_court'] }}</li>
                </ul>
                <p class="mt-3 flex flex-wrap gap-x-6 gap-y-2"><a href="#tout-savoir" class="lien-fort">{{ $b['tout_savoir_lien'] }}</a><a href="{{ route('boutiques.reglement') }}" class="lien-fort">{{ $b['lire_reglement'] }}</a></p>
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

            <section class="mt-10" aria-labelledby="plan-titre" data-plan x-data="{ d: null }">
                <h2 id="plan-titre" class="!text-xl">{{ $p['titre'] }}</h2>

                {{-- Choix : site, type (avec « Tous les types »), niveau, bloc. --}}
                @foreach ([['site', $p['site'], $sites->mapWithKeys(fn ($s) => [$s->id => ($s->code ? $s->code.' · ' : '').$s->nom]), $site?->id],
                           ['type', $p['type'], $types->count() > 1 ? collect(['' => $p['tous_types']])->union($types->mapWithKeys(fn ($t) => [$t->id => $t->code.' · '.$t->libelle])) : collect(), $type?->id ?? ''],
                           ['niveau', $p['niveau'], $niveaux->keys()->mapWithKeys(fn ($n) => [$n => $n]), $niveau],
                           ['bloc', $p['bloc'], collect($blocs)->mapWithKeys(fn ($bl) => [$bl => $bl]), $bloc]] as [$cle, $libelle, $options, $actuel])
                    @if ($options->isNotEmpty())
                        <nav class="plan-choix mt-4" aria-label="{{ $libelle }}" data-choix="{{ $cle }}">
                            <span class="plan-choix-libelle">{{ $libelle }}</span>
                            @foreach ($options as $valeur => $texte)
                                @php
                                    $choix = match ($cle) {
                                        'site' => ['site' => $valeur, 'type' => null, 'niveau' => null, 'bloc' => null],
                                        'type' => ['type' => $valeur === '' ? null : $valeur, 'niveau' => null, 'bloc' => null],
                                        'niveau' => ['niveau' => $valeur, 'bloc' => null],
                                        default => ['bloc' => $valeur],
                                    };
                                @endphp
                                <a href="{{ $lien($choix) }}"
                                   @class(['plan-choix-option', 'est-actif' => (string) $valeur === (string) $actuel]) @if ((string) $valeur === (string) $actuel) aria-current="page" @endif>{{ $texte }}</a>
                            @endforeach
                        </nav>
                    @endif
                @endforeach

                {{-- Légende : couleur du statut, et sigle de chaque type. --}}
                <ul class="plan-legende mt-5" aria-label="{{ $p['legende'] }}">
                    @foreach (['disponible', 'pre_reservee', 'reservee', 'attribuee'] as $statut)
                        <li><span class="plan-pastille plan-{{ $statut }}" aria-hidden="true"></span>{{ $p['statuts'][$statut] }}</li>
                    @endforeach
                </ul>
                @if ($types->isNotEmpty())
                    <ul class="plan-legende plan-legende-types mt-2" aria-label="{{ $p['legende_types'] }}" data-legende-types>
                        @foreach ($types as $t)
                            <li><span class="plan-sigle">{{ $t->code }}</span>{{ $t->libelle }}</li>
                        @endforeach
                    </ul>
                @endif

                @if ($plan->isEmpty())
                    <p class="texte-carte mt-4">{{ $p['aucune'] }}</p>
                @else
                    <p class="texte-carte mt-4">{{ $p['detail_aide'] }}</p>
                    <ul class="plan-boutiques mt-3" data-bloc="{{ $niveau }}-{{ $bloc }}">
                        @foreach ($plan as $boutique)
                            @php
                                $statut = $boutique->statut->surPlan();
                                // Limite atteinte pour ce type : la boutique reste visible mais n'est plus réservable pour ce citoyen.
                                $limiteAtteinte = $limites['restants'] !== null && ($limites['restants'][$boutique->type_boutique_id] ?? 0) === 0;
                                $detail = [...\App\Services\Boutiques\ReservationsBoutiques::detail($boutique), 'statut' => $p['statuts'][$statut],
                                    'limite' => $limiteAtteinte && $statut === 'disponible' ? $b['limites']['atteinte'] : null,
                                    'lien' => $statut === 'disponible' && $ouverte && ! $limiteAtteinte ? route('boutiques.boutique', $boutique->code) : null];
                                $survol = $boutique->code.' · '.$boutique->type?->libelle.($boutique->surface ? ' · '.format_nombre((float) $boutique->surface, 1).' m²' : '');
                            @endphp
                            <li>
                                @if ($detail['lien'])
                                    <a href="{{ $detail['lien'] }}" class="plan-case plan-{{ $statut }}" data-statut="{{ $statut }}" data-type="{{ $boutique->type?->code }}" title="{{ $survol }}"
                                       aria-label="{{ __('public.boutiques.plan.choisir', ['code' => $boutique->code]) }}" @mouseenter="d = @js($detail)" @focus="d = @js($detail)">
                                        @include('public.boutiques._case', ['boutique' => $boutique, 'statut' => $statut])
                                    </a>
                                @else
                                    <button type="button" class="plan-case plan-{{ $statut }}" data-statut="{{ $statut }}" data-type="{{ $boutique->type?->code }}" title="{{ $survol }}"
                                            aria-label="{{ __('public.boutiques.plan.boutique', ['code' => $boutique->code, 'statut' => $p['statuts'][$statut]]) }}"
                                            aria-controls="plan-detail" @mouseenter="d = @js($detail)" @click="d = @js($detail)">
                                        @include('public.boutiques._case', ['boutique' => $boutique, 'statut' => $statut, 'limite' => $detail['limite']])
                                    </button>
                                @endif
                            </li>
                        @endforeach
                    </ul>

                    {{-- Détail de l'emplacement survolé ou choisi (sans JavaScript : le récapitulatif de chaque boutique disponible). --}}
                    <section id="plan-detail" class="plan-detail carte mt-4 p-4" x-show="d" x-cloak aria-live="polite" data-plan-detail>
                        <template x-if="d">
                            <div>
                                <p class="flex flex-wrap items-center gap-2"><strong class="plan-code" x-text="d.code"></strong><span class="plan-sigle" x-text="d.type_code"></span><span class="texte-carte" x-text="d.statut"></span></p>
                                <p class="mt-1 font-semibold text-encre" x-text="d.type"></p>
                                <p class="texte-carte" x-show="d.type_description" x-text="d.type_description"></p>
                                <dl class="plan-detail-grille mt-3">
                                    <dt>{{ $p['detail']['site'] }}</dt><dd x-text="d.site"></dd>
                                    <dt>{{ $p['detail']['niveau'] }}</dt><dd x-text="d.niveau"></dd>
                                    <dt>{{ $p['detail']['bloc'] }}</dt><dd x-text="d.bloc"></dd>
                                    <dt>{{ $p['detail']['surface'] }}</dt><dd x-text="d.surface || @js($p['detail']['non_renseigne'])"></dd>
                                </dl>
                                <p class="mt-2 texte-carte" x-show="d.observations"><span class="font-semibold">{{ $p['detail']['observations'] }} :</span> <span x-text="d.observations"></span></p>
                                <dl class="plan-detail-grille mt-3" x-show="d.montants">
                                    <dt>{{ $p['detail']['loyer'] }}</dt><dd x-text="d.montants?.loyer"></dd>
                                    <dt>{{ $p['detail']['caution'] }}</dt><dd x-text="d.montants?.caution"></dd>
                                    <dt>{{ $p['detail']['avance'] }}</dt><dd x-text="d.montants?.avance"></dd>
                                    <dt class="font-semibold text-encre">{{ $p['detail']['total'] }}</dt><dd class="font-bold text-vert-fonce" x-text="d.montants?.total"></dd>
                                </dl>
                                <p class="plan-limite mt-3" x-show="d.limite" x-text="d.limite" role="status"></p>
                                <a x-show="d.lien" :href="d.lien" class="btn-primaire mt-3">{{ $p['detail']['reserver'] }}</a>
                            </div>
                        </template>
                    </section>
                @endif
            </section>

            {{-- Tout savoir : présentation de la campagne (mise en forme simple), étapes, indépendance avec les actions. --}}
            <section id="tout-savoir" class="mt-10" aria-labelledby="tout-savoir-titre">
                <h2 id="tout-savoir-titre" class="!text-xl">{{ $b['tout_savoir'] }}</h2>
                @if ($campagne->description)
                    <details class="boutiques-repli" data-presentation>
                        <summary>{{ $b['presentation'] }}</summary>
                        <div class="texte-riche">{{ \App\Support\MarkdownLeger::html($campagne->description) }}</div>
                    </details>
                @endif
                <details class="boutiques-repli">
                    <summary>{{ $b['comment_titre'] }}</summary>
                    <ol class="mt-2 grid gap-3 tab:grid-cols-2 lg:grid-cols-4">
                        @foreach ($b['etapes'] as $i => $etape)
                            <li class="carte p-4">
                                <span class="flex h-8 w-8 items-center justify-center rounded-full bg-vert-fonce font-bold text-white" aria-hidden="true">{{ $i + 1 }}</span>
                                <p class="mt-2 font-bold text-encre">{{ $etape['titre'] }}</p>
                                <p class="texte-carte">{{ $etape['texte'] }}</p>
                            </li>
                        @endforeach
                    </ol>
                </details>
                <details class="boutiques-repli">
                    <summary>{{ $b['independance_titre'] }}</summary>
                    <p>{{ $b['independance'] }}</p>
                </details>
                <p class="mt-4"><a href="{{ route('boutiques.reglement') }}" class="lien-fort">{{ $b['lire_reglement'] }}</a></p>
            </section>
        @endif
    </div>
</x-public.layout>
