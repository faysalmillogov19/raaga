{{--
    Accueil de la phase 1 (boutiques) : carrousel, chiffres clés, étapes, ce que l'on verse, sites, questions, aide.
    Chiffres et dates lus dans la base ou les paramètres de la plateforme ; aucun montant.
--}}
@php
    use App\Support\Diapositives;

    $a = __('public.accueil_raaga');
    $numeroVert = plateforme('contacts.numeroVert');
    $mois = fn (array $plage) => $plage[0] === $plage[1]
        ? trans_choice('public.accueil_raaga.mois', $plage[0], ['n' => $plage[0]])
        : __('public.accueil_raaga.mois_plage', ['min' => $plage[0], 'max' => $plage[1]]);
@endphp

<x-public.layout :titre="$a['titre_page']">
    @if ($premiere = $diapositives->first())
        <x-slot:head>
            {{-- Première image du carrousel chargée en priorité (la plus adaptée à l'écran). --}}
            <link rel="preload" as="image" type="image/webp" imagesrcset="{{ Diapositives::srcset($premiere['image']) }}" imagesizes="100vw" fetchpriority="high">
        </x-slot:head>
    @endif

    {{-- 1. Carrousel --}}
    <x-public.carrousel :diapositives="$diapositives" :url-candidater="$urlCandidater" />

    {{-- 2. Promesse, puis les deux démarches côte à côte (empilées sur mobile), chacune avec son code visuel. --}}
    @php($d = __('public.demarches'))
    <section class="acc-demarches" aria-labelledby="acc-demarches-titre">
        <div class="conteneur">
            <p class="acc-promesse">{{ $d['promesse'] }}</p>
            <h2 id="acc-demarches-titre" class="sr-only">{{ $d['titre'] }}</h2>
            <div class="acc-demarches-grille">
                @if ($investir)
                    <article class="acc-carte acc-demarche demarche-investir">
                        <span class="picto-demarche"><x-icon name="piece-pousse" class="h-7 w-7" data-picto="piece-pousse" /></span>
                        <x-public.badge-demarche demarche="investir" />
                        <h3 class="acc-h2">{{ $d['investir']['titre'] }}</h3>
                        @if ($investir['valeur'])
                            <ul class="acc-demarche-points">
                                <li>{{ __('public.demarches.investir.valeur_indicative', ['prix' => format_fcfa($investir['valeur'])]) }}</li>
                                <li>{{ trans_choice('public.demarches.investir.minimum', $investir['minimum'], ['n' => format_nombre($investir['minimum'])]) }}</li>
                            </ul>
                        @else
                            <p data-invest-bientot>{{ $d['investir']['bientot_invest'] }}</p>
                        @endif
                        @if ($jauge = $investir['jauge'])
                            {{-- Jauge de collecte : peut dépasser 100 % de l'objectif (la barre s'arrête au bout). --}}
                            <div class="acc-jauge" data-jauge-invest>
                                <p class="font-semibold">{{ __('public.demarches.investir.objectif', ['objectif' => format_fcfa($jauge['objectif'])]) }}</p>
                                <div class="acc-jauge-barre" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ (int) $jauge['largeur'] }}"
                                     aria-valuetext="{{ __('public.demarches.investir.pourcentage', ['pourcentage' => $jauge['libelle']]) }}">
                                    <span style="width: {{ round($jauge['largeur'], 1) }}%"></span>
                                </div>
                                <p>{{ __('public.demarches.investir.collecte', ['collecte' => format_fcfa($jauge['collecte'])]) }} ·
                                    <strong data-pourcentage-objectif>{{ __('public.demarches.investir.pourcentage', ['pourcentage' => $jauge['libelle']]) }}</strong></p>
                            </div>
                            <a href="{{ route('invest.simulateur') }}" class="lien-fort">{{ $d['investir']['simuler'] }}</a>
                        @endif
                        <p class="acc-demarche-tirage"><x-icon name="check" class="h-5 w-5 shrink-0" /> <span>{{ $d['investir']['dividendes'] }}</span></p>
                        <p class="acc-demarche-risque"><x-icon name="danger" class="h-5 w-5 shrink-0" /> <span>{{ $d['investir']['risque'] }}</span></p>
                        <a href="{{ $investir['lien'] }}" class="btn-demarche acc-demarche-bouton">{{ $investir['ouverte'] ? $d['investir']['bouton'] : $d['bientot'] }}</a>
                        <p class="acc-demarche-rappel">{{ $d['actions'] }}</p>
                    </article>
                @else
                    {{-- Annonce permanente : ni prix, ni lien vers les pages des actions ; seulement l'alerte SMS. --}}
                    @php($n = $d['annonce'])
                    <article class="acc-carte acc-demarche demarche-investir" data-annonce-investir>
                        <span class="picto-demarche"><x-icon name="piece-pousse" class="h-7 w-7" data-picto="piece-pousse" /></span>
                        <x-public.badge-demarche demarche="investir" />
                        <h3 class="acc-h2">{{ $n['titre'] }}</h3>
                        <p>{{ $n['texte'] }}</p>
                        @if (session('alerte_enregistree'))
                            <p class="acc-demarche-tirage" role="status"><x-icon name="check" class="h-5 w-5 shrink-0" /> <span>{{ __('public.demarches.alerte.enregistree') }}</span></p>
                        @else
                            <details class="acc-annonce-alerte acc-demarche-bouton" @if ($errors->hasAny(['telephone', 'consentement'])) open @endif>
                                <summary class="btn-demarche">{{ $n['bouton'] }}</summary>
                                <form method="POST" action="{{ route('souscription.bientot.store') }}" class="mt-4 space-y-3" novalidate>
                                    @csrf
                                    <div>
                                        <label for="annonce-telephone" class="font-semibold"><x-requis /> {{ __('public.demarches.alerte.telephone') }}</label>
                                        <input id="annonce-telephone" name="telephone" type="tel" inputmode="tel" autocomplete="tel" value="{{ old('telephone') }}"
                                               class="champ mt-1" aria-describedby="annonce-telephone-aide">
                                        <p id="annonce-telephone-aide" class="texte-carte">{{ __('public.demarches.alerte.telephone_aide') }}</p>
                                        @if ($errors->has('telephone'))<p class="message-erreur" role="alert">{{ $errors->first('telephone') }}</p>@endif
                                    </div>
                                    <div>
                                        <x-public.case nom="consentement" id="annonce-consentement" value="1" :libelle="__('public.demarches.alerte.consentement')" obligatoire aria-required="true" />
                                        @if ($errors->has('consentement'))<p class="message-erreur" role="alert">{{ $errors->first('consentement') }}</p>@endif
                                    </div>
                                    <button type="submit" class="btn-demarche">{{ __('public.demarches.alerte.envoyer') }}</button>
                                </form>
                            </details>
                        @endif
                    </article>
                @endif
                <article class="acc-carte acc-demarche demarche-boutiques">
                    <span class="picto-demarche"><x-icon name="boutique-auvent" class="h-7 w-7" data-picto="boutique-auvent" /></span>
                    <x-public.badge-demarche demarche="boutiques" />
                    <h3 class="acc-h2">{{ $d['boutique']['titre'] }}</h3>
                    <ul class="acc-demarche-points">
                        <li>{{ $d['boutique']['caution'] }}</li>
                        <li>{{ $d['boutique']['avance'] }}</li>
                    </ul>
                    <p class="acc-demarche-tirage"><x-icon name="check" class="h-5 w-5 shrink-0" /> <span>{{ $d['boutique']['tirage'] }}</span></p>
                    <a href="{{ $urlCandidater }}" class="btn-demarche acc-demarche-bouton">{{ $d['boutique']['bouton'] }}</a>
                    <p class="acc-demarche-rappel">{{ $d['boutiques'] }}</p>
                </article>
            </div>
        </div>
    </section>

    @if ($investir)
        {{-- D'où viennent les dividendes ? (démarche « Investir ») --}}
        @php($o = __('public.demarches.origine'))
        <section class="acc-section acc-section-verte demarche-investir" aria-labelledby="acc-origine-titre" data-apparition>
            <div class="conteneur">
                <h2 id="acc-origine-titre" class="acc-h2">{{ $o['titre'] }}</h2>
                <ul class="acc-origine">
                    @foreach (['loyers' => 'boutique-auvent', 'parkings' => 'parking', 'services' => 'services'] as $cle => $picto)
                        <li class="acc-carte acc-origine-item">
                            <span class="picto-demarche"><x-icon :name="$picto" class="h-7 w-7" /></span>
                            <span class="font-bold text-encre">{{ $o['sources'][$cle] }}</span>
                        </li>
                    @endforeach
                </ul>
                <p class="acc-origine-phrase">{{ $o['phrase'] }}</p>
            </div>
        </section>
    @endif

    {{-- 3. Chiffres clés --}}
    <section class="acc-chiffres" aria-labelledby="acc-chiffres-titre">
        <h2 id="acc-chiffres-titre" class="sr-only">{{ $a['chiffres_titre'] }}</h2>
        <dl class="conteneur acc-chiffres-grille">
            @foreach ($chiffres as $cle => $n)
                <div class="acc-chiffre">
                    <dt>{{ $a['chiffres'][$cle] }}</dt>
                    <dd data-compteur="{{ $n }}">{{ format_nombre($n) }}</dd>
                </div>
            @endforeach
            <div class="acc-chiffre acc-chiffre-campagne">
                <dt class="sr-only">{{ $a['comment'] }}</dt>
                <dd class="acc-campagne">
                    <span @class(['acc-pastille', 'acc-pastille-ouverte' => $ouverte])></span>
                    {{ $ouverte ? __('public.accueil_raaga.campagne_ouverte', ['date' => $campagne->date_cloture->translatedFormat('j F Y')]) : $a['campagne_bientot'] }}
                </dd>
            </div>
        </dl>
    </section>

    {{-- 3. Comment ça marche --}}
    <section id="comment" class="acc-section" aria-labelledby="acc-etapes-titre" data-apparition>
        <div class="conteneur">
            <p class="surtitre">{{ $a['etapes_surtitre'] }}</p>
            <h2 id="acc-etapes-titre" class="acc-h2">{{ $a['etapes_titre'] }}</h2>
            <ol class="acc-etapes">
                @foreach ($a['etapes'] as $i => $etape)
                    <li class="acc-carte acc-etape">
                        <span class="acc-etape-numero" aria-hidden="true">{{ $i + 1 }}</span>
                        <span class="acc-etape-icone" aria-hidden="true"><x-icon :name="$etape['icone']" class="h-6 w-6" /></span>
                        <h3 class="acc-h3"><span class="sr-only">{{ $i + 1 }}. </span>{{ $etape['titre'] }}</h3>
                        <p>{{ $etape['texte'] }}</p>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    {{-- 4. Ce que vous versez --}}
    <section class="acc-section acc-section-verte" aria-labelledby="acc-versements-titre" data-apparition>
        <div class="conteneur">
            <p class="surtitre">{{ $a['versements_surtitre'] }}</p>
            <h2 id="acc-versements-titre" class="acc-h2">{{ $a['versements_titre'] }}</h2>
            <div class="acc-versements">
                <div class="acc-carte">
                    <h3 class="acc-h3">{{ $a['caution_titre'] }}</h3>
                    <p>{{ $a['caution_texte'] }}</p>
                </div>
                <div class="acc-carte">
                    <h3 class="acc-h3">{{ $a['avance_titre'] }}</h3>
                    <p>{{ $a['avance_texte'] }}</p>
                </div>
            </div>
            <p class="acc-note"><x-icon name="info" class="h-5 w-5 shrink-0" /> <span>{{ __('public.accueil_boutiques.regles.2.texte') }}</span></p>
            <div class="acc-carte acc-durees">
                <h3 class="acc-h3">{{ $a['durees_titre'] }}</h3>
                @if ($durees->isEmpty())
                    <p class="acc-durees-vide" role="status">{{ $a['durees_vides'] }}</p>
                @else
                    <table class="acc-tableau">
                        <thead><tr>@foreach ($a['durees_colonnes'] as $cle => $libelle)<th scope="col" @class(['acc-nombre' => $cle !== 'niveau'])>{{ $libelle }}</th>@endforeach</tr></thead>
                        <tbody>
                            @foreach ($durees as $ligne)
                                <tr><th scope="row">{{ $ligne['niveau'] }}</th><td class="acc-nombre">{{ $mois($ligne['caution']) }}</td><td class="acc-nombre">{{ $mois($ligne['avance']) }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    </section>

    {{-- 5. Les sites --}}
    <section class="acc-section" aria-labelledby="acc-sites-titre" data-apparition>
        <div class="conteneur">
            <p class="surtitre">{{ $a['sites_surtitre'] }}</p>
            <h2 id="acc-sites-titre" class="acc-h2">{{ $a['sites_titre'] }}</h2>
            @if ($sites->isEmpty())
                <p class="mt-4">{{ $a['sites_vides'] }}</p>
            @else
                <ul class="acc-sites">
                    @foreach ($sites as $site)
                        <li class="acc-carte acc-site">
                            <img src="{{ asset('images/accueil/banniere-640.webp') }}" alt="" loading="lazy" decoding="async" width="640" height="360" class="acc-site-image">
                            <div class="acc-site-texte">
                                <h3 class="acc-h3">{{ $site->nom }}</h3>
                                @if ($site->localite())<p class="acc-site-ville"><x-icon name="lieu" class="h-4 w-4" /> {{ $site->localite() }}</p>@endif
                                <p>{{ trans_choice('public.accueil_raaga.sites_boutiques', $site->boutiques_count, ['n' => format_nombre($site->boutiques_count)]) }}</p>
                                <a href="{{ route('boutiques') }}" class="acc-btn acc-btn-vert">{{ $a['sites_voir'] }}<span class="sr-only"> : {{ $site->nom }}</span></a>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </section>

    {{-- 6. Questions fréquentes --}}
    <section class="acc-section acc-section-verte" aria-labelledby="acc-faq-titre" data-apparition>
        <div class="conteneur">
            <p class="surtitre">{{ $a['faq_surtitre'] }}</p>
            <h2 id="acc-faq-titre" class="acc-h2">{{ $a['faq_titre'] }}</h2>
            <div class="faq-liste">
                @foreach ($questions as $question)
                    <x-public.question :question="$question['q']">{{ $question['r'] }}</x-public.question>
                @endforeach
            </div>
            <a href="{{ route('faq') }}" class="lien-fort">{{ $a['faq_toutes'] }} <x-icon name="fleche" class="h-4 w-4" /></a>
        </div>
    </section>

    {{-- 7. Besoin d'aide ? --}}
    <section class="acc-aide" aria-labelledby="acc-aide-titre">
        <div class="conteneur acc-aide-grille">
            <div>
                <h2 id="acc-aide-titre" class="acc-h2 !text-white">{{ $a['aide_titre'] }}</h2>
                <p class="mt-2">{{ $a['aide_texte'] }}</p>
                <ul class="acc-aide-liens">
                    @if ($numeroVert)
                        <li><a href="tel:{{ preg_replace('/\s+/', '', $numeroVert) }}" class="acc-btn acc-btn-jaune"><x-icon name="telephone" class="h-5 w-5" /> {{ __('public.accueil_raaga.aide_numero', ['numero' => \App\Support\Telephone::formaterLocal($numeroVert)]) }}</a></li>
                    @endif
                    <li><a href="{{ route('guichets') }}" class="acc-btn acc-btn-contour"><x-icon name="lieu" class="h-5 w-5" /> {{ $a['aide_guichets'] }}</a></li>
                    <li><a href="{{ route('contact') }}" class="acc-btn acc-btn-contour"><x-icon name="courriel" class="h-5 w-5" /> {{ $a['aide_contact'] }}</a></li>
                </ul>
            </div>
            <p class="acc-fraude" role="note"><x-icon name="danger" class="h-6 w-6 shrink-0" /> <span>{{ $a['fraude'] }}</span></p>
        </div>
    </section>
</x-public.layout>
