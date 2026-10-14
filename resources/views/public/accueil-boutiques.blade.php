{{--
    Accueil de la phase 1 (boutiques) : bannière, chiffres clés, étapes, ce que l'on verse, sites, questions, aide.
    Chiffres et dates lus dans la base ou les paramètres de la plateforme ; aucun montant.
--}}
@php
    use App\Support\BanniereAccueil;

    $a = __('public.accueil_raaga');
    $numeroVert = plateforme('contacts.numeroVert');
    $mois = fn (array $plage) => $plage[0] === $plage[1]
        ? trans_choice('public.accueil_raaga.mois', $plage[0], ['n' => $plage[0]])
        : __('public.accueil_raaga.mois_plage', ['min' => $plage[0], 'max' => $plage[1]]);
@endphp

<x-public.layout :titre="$a['titre_page']">
    <x-slot:head>
        {{-- Bannière chargée en priorité (image la plus adaptée à l'écran). --}}
        <link rel="preload" as="image" type="image/webp" imagesrcset="{{ BanniereAccueil::srcset() }}" imagesizes="100vw" fetchpriority="high">
    </x-slot:head>

    {{-- 1. Bannière --}}
    <section class="acc-banniere" aria-labelledby="acc-titre">
        <img src="{{ BanniereAccueil::url(1280) }}" srcset="{{ BanniereAccueil::srcset() }}" sizes="100vw" alt="{{ $a['image_alt'] }}"
             class="acc-banniere-image" fetchpriority="high" decoding="async" width="1920" height="1080">
        <div class="acc-banniere-voile" aria-hidden="true"></div>
        <div class="conteneur acc-banniere-contenu">
            <h1 id="acc-titre" class="acc-titre">{{ $a['titre'] }}</h1>
            <p class="acc-sous-titre">{{ $a['sous_titre'] }}</p>
            <div class="acc-banniere-boutons">
                <a href="{{ $urlCandidater }}" class="acc-btn acc-btn-jaune">{{ $a['candidater'] }}</a>
                <a href="#comment" class="acc-btn acc-btn-contour">{{ $a['comment'] }}</a>
            </div>
        </div>
    </section>

    {{-- 2. Chiffres clés --}}
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
                            <img src="{{ BanniereAccueil::url(640) }}" alt="" loading="lazy" decoding="async" width="640" height="360" class="acc-site-image">
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
