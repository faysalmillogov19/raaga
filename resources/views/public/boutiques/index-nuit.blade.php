{{--
    « Obtenir une boutique », thème « nuit » (maquette docs/maquettes/maquette-boutiques-theme-nuit.html). Mêmes données,
    mêmes états et mêmes règles que la version claire (public.boutiques.index) : campagne (état, compte à rebours, jauge,
    période, limite, délais), plan en pilules et cases (choix en jaune), fiche blanche des montants, « Comment ça marche »,
    « Sans lien avec les actions ». Aucune donnée personnelle : seul le statut de chaque boutique est public.
--}}
@php
    use App\Models\Niveau;
    use App\Services\Boutiques\ReservationsBoutiques;
    use App\Support\TitreNuit;

    $b = __('public.boutiques');
    $p = $b['plan'];
    $n = __('public.nuit');
    $titre = $b['titre'];
    $lien = fn (array $choix) => route('boutiques', array_filter(['site' => $site?->id, 'type' => $type?->id, 'niveau' => $niveau, 'bloc' => $bloc, ...$choix], fn ($v) => $v !== null));
    $libellesNiveaux = Niveau::query()->whereIn('code', $niveaux->keys())->pluck('libelle', 'code');
    $icones = ['pre_reservee' => 'horloge', 'reservee' => 'cadenas', 'attribuee' => 'check'];
@endphp

<x-public.layout :titre="$titre" :sombre="true">
    <x-public.page-entete :titre="$titre" :intro="$b['intro']" :fil="[['libelle' => $titre]]" :sombre="true" />

    @if (! $campagne)
        <div class="conteneur nuit-page-corps">
            <x-etat-vide icone="boutiques" :titre="$b['vide_titre']" :lien="route('faq')" :libelle-lien="__('public.etats_vides.liens.faq')">{{ $b['vide'] }}</x-etat-vide>
        </div>
    @else
        @php
            $etat = $campagne->etatPublic();
            $info = app(\App\Services\Publique\AccueilNuit::class)->campagne($campagne);
            $c = $n['campagne'];
        @endphp

        {{-- Campagne : état, texte, faits ; compte à rebours et jauge des boutiques réservées. --}}
        <section class="conteneur nuit-page-corps" aria-labelledby="campagne-titre">
            <div class="nuit-campagne" data-etat-campagne="{{ $etat }}">
                <div class="nuit-carte nuit-campagne-carte">
                    <div class="nuit-campagne-entete">
                        <h2 id="campagne-titre" class="nuit-campagne-nom">{{ $campagne->nom }}</h2>
                        <span class="nuit-etat nuit-etat-{{ $etat }}">{{ $b['etats'][$etat]['badge'] }}</span>
                    </div>
                    @if ($etat === 'bientot')
                        <div class="nuit-campagne-texte" data-campagne-fermee>
                            <p class="font-bold">{{ $b['etats']['bientot']['titre'] }}</p>
                            @if ($campagne->date_ouverture?->isFuture())
                                <p>{{ __('public.boutiques.etats.bientot.le', ['date' => $campagne->date_ouverture->translatedFormat('j F Y à H\hi')]) }}</p>
                                <p class="nuit-secondaire">{{ $b['etats']['bientot']['plan'] }}</p>
                            @else
                                <p>{{ $b['etats']['bientot']['date_a_venir'] }}</p>
                            @endif
                        </div>
                    @elseif ($etat === 'ouverte')
                        <p class="nuit-campagne-texte nuit-secondaire" data-campagne-ouverte>{{ __('public.boutiques.etats.ouverte.texte', ['fin' => $campagne->date_cloture->translatedFormat('j F Y à H\hi')]) }}</p>
                    @else
                        <p class="nuit-campagne-texte nuit-secondaire" data-campagne-fermee>{{ $b['etats']['terminee']['texte'] }}</p>
                    @endif
                    <dl class="nuit-faits">
                        <div><dt>{{ $c['periode_titre'] }}</dt><dd>{{ __('public.nuit.campagne.periode', ['debut' => $campagne->date_ouverture->format('d/m/Y'), 'fin' => $campagne->date_cloture->format('d/m/Y')]) }}</dd></div>
                        <div><dt>{{ $c['attribution_titre'] }}</dt><dd>{{ $c['attribution'] }}</dd></div>
                        <div><dt>{{ $c['limite_titre'] }}</dt>
                            <dd>
                                @if ($limites['restants'] === null && $info['limite_uniforme'] !== null)
                                    <span class="nuit-vif">{{ __('public.nuit.campagne.limite_uniforme', ['n' => $info['limite_uniforme']]) }}</span>
                                    <span class="nuit-faits-detail" data-limites>{{ $limites['resume'] }}</span>
                                @else
                                    <span data-limites>{{ $limites['resume'] }}</span>
                                @endif
                            </dd></div>
                        <div><dt>{{ $c['delais_titre'] }}</dt><dd>{{ __('public.nuit.campagne.delais', ['caution' => $info['delai_caution'], 'avance' => $info['delai_avance']]) }}</dd></div>
                    </dl>
                    <p class="nuit-liens"><a href="#tout-savoir">{{ $b['tout_savoir_lien'] }}</a><a href="{{ route('boutiques.reglement') }}">{{ $b['lire_reglement'] }}</a></p>
                    <p class="sr-only">{{ $b['premier_arrive'] }} {{ $b['independance_court'] }}</p>
                </div>
                <div class="nuit-carte nuit-campagne-rebours">
                    @if ($info['cible'])
                        <x-public.compte-a-rebours :cible="$info['cible']" :titre="$etat === 'ouverte' ? $c['cloture_dans_court'] : $c['ouverture_dans_court']" :messages="$b['compte_a_rebours']" />
                    @endif
                    <x-public.jauge class="nuit-campagne-jauge" :valeur="$info['reservees']" :max="max(1, $info['total'])"
                                    :libelle="__('public.nuit.campagne.reservees', ['n' => format_nombre($info['reservees']), 'total' => format_nombre($info['total'])])" />
                </div>
            </div>

            @if ($mesReservations->isNotEmpty())
                <x-public.carte-sombre class="mt-6" :titre="$b['mes_reservations']" :niveau="2">
                    <ul class="space-y-1">
                        @foreach ($mesReservations as $r)
                            <li><a href="{{ route('boutiques.reservation.show', $r->reference) }}" class="font-bold">{{ $r->boutique?->code }}</a> · {{ $r->statut->label() }}</li>
                        @endforeach
                    </ul>
                </x-public.carte-sombre>
            @endif
        </section>

        {{-- Plan : choix en pilules, légende, cases, fiche blanche de l'emplacement survolé ou choisi. --}}
        @php
            $disponibles = $plan->filter(fn ($bt) => $bt->statut->surPlan() === 'disponible')->count();
            $details = $plan->mapWithKeys(function ($boutique) use ($limites, $p, $b, $ouverte) {
                $statut = $boutique->statut->surPlan();
                $limiteAtteinte = $limites['restants'] !== null && ($limites['restants'][$boutique->type_boutique_id] ?? 0) === 0;

                return [$boutique->code => [...ReservationsBoutiques::detail($boutique), 'statut' => $p['statuts'][$statut],
                    'limite' => $limiteAtteinte && $statut === 'disponible' ? $b['limites']['atteinte'] : null,
                    // Libre, pré-réservée ou réservée : on peut s'y inscrire (réservation ou liste d'attente).
                    'lien' => in_array($statut, ['disponible', 'pre_reservee', 'reservee'], true) && $ouverte ? route('boutiques.boutique', $boutique->code) : null,
                    'action' => $statut === 'disponible' ? $p['detail']['reserver'] : $p['detail']['inscrire']]];
            });
            $premier = $plan->first(fn ($bt) => $bt->statut->surPlan() === 'disponible') ?? $plan->first();
        @endphp
        <section class="conteneur nuit-page-section" aria-labelledby="plan-titre" data-plan x-data="{ d: @js($premier ? $details[$premier->code] : null) }">
            <p class="nuit-surtitre">{{ $p['titre'] }}</p>
            <h2 id="plan-titre" class="nuit-titre">{{ $n['boutiques']['plan_titre'] }} <strong>{{ $n['boutiques']['plan_fort'] }}</strong></h2>

            <div class="nuit-plan-choix">
                @foreach ([['site', $p['site'], $sites->mapWithKeys(fn ($s) => [$s->id => ($s->code ? $s->code.' · ' : '').$s->nom]), $site?->id],
                           ['type', $p['type'], $types->count() > 1 ? collect(['' => $p['tous_types']])->union($types->mapWithKeys(fn ($t) => [$t->id => $t->code.' · '.$t->libelle])) : collect(), $type?->id ?? ''],
                           ['niveau', $p['niveau'], $niveaux->keys()->mapWithKeys(fn ($nv) => [$nv => $libellesNiveaux[$nv] ?? $nv]), $niveau],
                           ['bloc', $p['bloc'], collect($blocs)->mapWithKeys(fn ($bl) => [$bl => $p['bloc'].' '.$bl]), $bloc]] as [$cle, $libelle, $options, $actuel])
                    @if ($options->isNotEmpty())
                        <nav class="nuit-plan-groupe" aria-label="{{ $libelle }}" data-choix="{{ $cle }}">
                            <p class="nuit-plan-libelle">{{ $libelle }}</p>
                            <div class="nuit-plan-pilules">
                                @foreach ($options as $valeur => $texte)
                                    @php
                                        $choix = match ($cle) {
                                            'site' => ['site' => $valeur, 'type' => null, 'niveau' => null, 'bloc' => null],
                                            'type' => ['type' => $valeur === '' ? null : $valeur, 'niveau' => null, 'bloc' => null],
                                            'niveau' => ['niveau' => $valeur, 'bloc' => null],
                                            default => ['bloc' => $valeur],
                                        };
                                        $actif = (string) $valeur === (string) $actuel;
                                    @endphp
                                    <a href="{{ $lien($choix) }}" class="nuit-pilule nuit-pilule-choix"{!! $actif ? ' aria-current="page"' : '' !!}>{{ $texte }}</a>
                                @endforeach
                            </div>
                        </nav>
                    @endif
                @endforeach
            </div>

            <ul class="nuit-plan-legende" aria-label="{{ $p['legende'] }}">
                @foreach (['disponible', 'pre_reservee', 'reservee', 'attribuee'] as $statut)
                    <li><span class="nuit-plan-point nuit-plan-{{ $statut }}" aria-hidden="true">@isset($icones[$statut])<x-icon :name="$icones[$statut]" />@endisset</span>{{ $p['statuts'][$statut] }}</li>
                @endforeach
            </ul>
            @if ($types->isNotEmpty())
                <ul class="nuit-plan-legende nuit-plan-legende-types" aria-label="{{ $p['legende_types'] }}" data-legende-types>
                    @foreach ($types as $t)
                        <li><span class="plan-sigle">{{ $t->code }}</span>{{ $t->libelle }}</li>
                    @endforeach
                </ul>
            @endif

            @if ($plan->isEmpty())
                <p class="nuit-carte mt-4">{{ $p['aucune'] }}</p>
            @else
                <div class="nuit-plan">
                    <div class="nuit-carte nuit-plan-carte">
                        <p class="nuit-plan-entete"><strong>{{ trans_choice('public.nuit.boutiques.plan_entete', $disponibles, ['niveau' => $libellesNiveaux[$niveau] ?? $niveau, 'bloc' => $p['bloc'].' '.$bloc, 'n' => format_nombre($disponibles)]) }}</strong> · {{ $p['detail_aide'] }}</p>
                        <ul class="nuit-plan-cases" data-bloc="{{ $niveau }}-{{ $bloc }}">
                            @foreach ($plan as $boutique)
                                @php
                                    $statut = $boutique->statut->surPlan();
                                    $detail = $details[$boutique->code];
                                    $survol = $boutique->code.' · '.$boutique->type?->libelle.($boutique->surface ? ' · '.format_nombre((float) $boutique->surface, 1).' m²' : '');
                                @endphp
                                <li>
                                    @if ($detail['lien'])
                                        <a href="{{ $detail['lien'] }}" class="nuit-case nuit-plan-{{ $statut }}" data-statut="{{ $statut }}" data-type="{{ $boutique->type?->code }}" title="{{ $survol }}"
                                           :class="d && d.code === @js($boutique->code) ? 'est-choisie' : ''"
                                           aria-label="{{ __('public.boutiques.plan.choisir', ['code' => $boutique->code]) }}" @mouseenter="d = @js($detail)" @focus="d = @js($detail)">@isset($icones[$statut])<x-icon :name="$icones[$statut]" class="nuit-case-icone" />@endisset{{ $boutique->numero }}@if ($detail['limite'])<span class="sr-only" data-limite-atteinte>{{ $detail['limite'] }}</span><span class="nuit-case-limite" aria-hidden="true">!</span>@endif</a>
                                    @else
                                        <button type="button" class="nuit-case nuit-plan-{{ $statut }}" data-statut="{{ $statut }}" data-type="{{ $boutique->type?->code }}" title="{{ $survol }}"
                                                :class="d && d.code === @js($boutique->code) ? 'est-choisie' : ''"
                                                aria-label="{{ __('public.boutiques.plan.boutique', ['code' => $boutique->code, 'statut' => $p['statuts'][$statut]]) }}"
                                                aria-controls="plan-detail" @mouseenter="d = @js($detail)" @click="d = @js($detail)">
                                            @isset($icones[$statut])<x-icon :name="$icones[$statut]" class="nuit-case-icone" />@endisset{{ $boutique->numero }}
                                            @if ($detail['limite'])<span class="sr-only" data-limite-atteinte>{{ $detail['limite'] }}</span><span class="nuit-case-limite" aria-hidden="true">!</span>@endif
                                        </button>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>

                    {{-- Fiche blanche de l'emplacement (sans JavaScript : le récapitulatif de chaque boutique disponible). --}}
                    <aside id="plan-detail" class="nuit-fiche nuit-plan-fiche" x-show="d" x-cloak aria-live="polite" data-plan-detail>
                        <template x-if="d">
                            <div>
                                <p class="nuit-fiche-surtitre"><span x-text="d.type_code"></span> · <span x-text="d.statut"></span></p>
                                <p class="nuit-fiche-titre" x-text="d.code"></p>
                                <dl class="nuit-fiche-lignes">
                                    <div class="nuit-fiche-ligne"><dt>{{ $p['detail']['niveau'] }}</dt><dd x-text="d.niveau"></dd></div>
                                    <div class="nuit-fiche-ligne"><dt>{{ $p['detail']['bloc'] }}</dt><dd x-text="d.bloc"></dd></div>
                                    <div class="nuit-fiche-ligne"><dt>{{ $p['type'] }}</dt><dd x-text="d.type"></dd></div>
                                    <div class="nuit-fiche-ligne est-separee"><dt>{{ $p['detail']['surface'] }}</dt><dd x-text="d.surface || @js($p['detail']['non_renseigne'])"></dd></div>
                                </dl>
                                <p class="nuit-fiche-note" x-show="d.observations"><span class="font-semibold">{{ $p['detail']['observations'] }} :</span> <span x-text="d.observations"></span></p>
                                <template x-if="d.montants">
                                    <div>
                                        <dl class="nuit-fiche-lignes">
                                            <div class="nuit-fiche-ligne est-montant"><dt>{{ $p['detail']['loyer'] }}</dt><dd x-text="d.montants.loyer"></dd></div>
                                            <div class="nuit-fiche-ligne est-montant"><dt>{{ $p['detail']['caution'] }}</dt><dd x-text="d.montants.caution"></dd></div>
                                            <div class="nuit-fiche-ligne est-montant est-separee"><dt>{{ $p['detail']['avance'] }}</dt><dd x-text="d.montants.avance"></dd></div>
                                        </dl>
                                        <p class="nuit-fiche-total-libelle">{{ $p['detail']['total'] }}</p>
                                        <p class="nuit-fiche-total" x-text="d.montants.total"></p>
                                    </div>
                                </template>
                                <p class="nuit-fiche-limite" x-show="d.limite" x-text="d.limite" role="status"></p>
                                <a x-show="d.lien" :href="d.lien" class="nuit-pilule nuit-pilule-plein" x-text="d.action">{{ $p['detail']['reserver'] }}</a>
                            </div>
                        </template>
                    </aside>
                </div>
            @endif
        </section>

        {{-- Tout savoir : comment ça marche (frise), sans lien avec les actions, présentation, règlement. --}}
        @php
            [$comment, $fortComment] = TitreNuit::decouper($b['comment_titre'], 2);
        @endphp
        <x-public.section id="tout-savoir" fond="alterne" :surtitre="$b['tout_savoir']" :titre="$comment" :fort="$fortComment" :centre="true" class="nuit-page-fin">
            <x-public.frise variante="dates" :etapes="array_map(fn ($e, $i) => ['date' => __('public.nuit.boutiques.etape', ['n' => $i + 1]), 'titre' => $e['titre'], 'detail' => $e['texte']], $b['etapes'], array_keys($b['etapes']))" />
            <div class="nuit-carte nuit-encadre">
                <x-public.pastille-icone couleur="rouge" texte="!" taille="moyenne" />
                <div>
                    <p class="nuit-encadre-titre">{{ $b['independance_titre'] }}</p>
                    <p class="nuit-secondaire">{{ $b['independance'] }}</p>
                </div>
            </div>
            @if ($campagne->description)
                <details class="nuit-carte nuit-repli" data-presentation>
                    <summary>{{ $b['presentation'] }}</summary>
                    <div class="texte-riche">{{ \App\Support\MarkdownLeger::html($campagne->description) }}</div>
                </details>
            @endif
            <p class="nuit-liens nuit-liens-centre"><a href="{{ route('boutiques.reglement') }}">{{ $b['lire_reglement'] }}</a></p>
        </x-public.section>
    @endif
</x-public.layout>
