@props(['diapositives', 'urlCandidater'])

{{--
    Carrousel plein écran de l'accueil (Paramètres > Site public > Carrousel). Défilement natif (scroll-snap) au doigt,
    fondu entre les diapositives, lecture automatique (6 s) arrêtée au survol, au focus, onglet caché ou animations
    réduites ; bouton Pause / Lecture, flèches, points et touches ← →. Seule la diapositive visible est exposée aux
    lecteurs d'écran. La première image est chargée en priorité, les suivantes à l'approche de leur tour.
    Sans diapositive active : une diapositive par défaut sur fond vert.
--}}
@php
    use App\Support\Diapositives;

    $a = __('public.accueil_raaga');
    $diapos = $diapositives->isNotEmpty() ? $diapositives->values() : collect([[
        'image' => null, 'alt' => null, 'titre' => $a['titre'], 'sousTitre' => $a['sous_titre'],
        'boutonLibelle' => $a['candidater'], 'boutonLien' => $urlCandidater,
    ]]);
    $n = $diapos->count();
@endphp

<section class="acc-carrousel" role="region" aria-roledescription="{{ $a['carrousel']['role'] }}" aria-label="{{ $a['carrousel']['nom'] }}"
         @if ($n > 1) data-carrousel @endif>
    <h1 class="sr-only">{{ $a['titre_page'] }}</h1>
    <div class="acc-carrousel-piste" data-piste @if ($n > 1) aria-live="off" @endif>
        @foreach ($diapos as $i => $d)
            @php($cadrage = '--cadrage: '.($d['cadrageX'] ?? 50).'% '.($d['cadrageY'] ?? 50).'%')
            <div @class(['acc-diapo', 'acc-diapo-defaut' => ! $d['image'], 'acc-diapo-droite' => ($d['texte'] ?? 'gauche') === 'droite', 'acc-diapo-panneau' => ($d['texte'] ?? 'gauche') === 'panneau']) role="group" aria-roledescription="{{ $a['carrousel']['diapositive'] }}"
                 aria-label="{{ $i + 1 }} sur {{ $n }}" data-diapo @if ($i > 0) aria-hidden="true" inert @endif>
                @if ($d['image'])
                    @if ($i === 0)
                        <img src="{{ Diapositives::url($d['image'], 1280) }}" srcset="{{ Diapositives::srcset($d['image']) }}" sizes="100vw"
                             alt="{{ $d['alt'] }}" class="acc-diapo-image" style="{{ $cadrage }}" fetchpriority="high" decoding="async" width="1920" height="1080">
                    @else
                        <img data-src="{{ Diapositives::url($d['image'], 1280) }}" data-srcset="{{ Diapositives::srcset($d['image']) }}" sizes="100vw"
                             alt="{{ $d['alt'] }}" class="acc-diapo-image" style="{{ $cadrage }}" decoding="async" width="1920" height="1080">
                    @endif
                    <div class="acc-banniere-voile" aria-hidden="true"></div>
                @endif
                <div class="conteneur acc-diapo-contenu">
                    @if ($d['titre'])<h2 class="acc-titre">{{ $d['titre'] }}</h2>@endif
                    @if ($d['sousTitre'])<p class="acc-sous-titre">{{ $d['sousTitre'] }}</p>@endif
                    @if ($d['boutonLibelle'] && $d['boutonLien'])
                        <a href="{{ $d['boutonLien'] }}" class="acc-btn acc-btn-jaune acc-diapo-bouton">{{ $d['boutonLibelle'] }}</a>
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    {{-- Hors des diapositives : le bouton « Comment ça marche » et les commandes restent en place. --}}
    <div class="conteneur acc-carrousel-barre">
        <a href="#comment" class="acc-btn acc-btn-contour">{{ $a['comment'] }}</a>
        @if ($n > 1)
            <div class="acc-carrousel-commandes">
                <button type="button" class="acc-carrousel-bouton" data-lecture aria-label="{{ $a['carrousel']['pause'] }}"
                        data-libelle-pause="{{ $a['carrousel']['pause'] }}" data-libelle-lecture="{{ $a['carrousel']['lecture'] }}">
                    <x-icon name="pause" data-icone-pause /><x-icon name="lecture" data-icone-lecture hidden />
                </button>
                <button type="button" class="acc-carrousel-bouton" data-precedente aria-label="{{ $a['carrousel']['precedente'] }}"><x-icon name="chevron" class="h-5 w-5 rotate-180" /></button>
                <div class="acc-carrousel-points">
                    @foreach ($diapos as $i => $d)
                        <button type="button" class="acc-carrousel-point" data-point="{{ $i }}" aria-label="{{ __('public.accueil_raaga.carrousel.aller', ['n' => $i + 1]) }}"
                                @if ($i === 0) aria-current="true" @endif></button>
                    @endforeach
                </div>
                <button type="button" class="acc-carrousel-bouton" data-suivante aria-label="{{ $a['carrousel']['suivante'] }}"><x-icon name="chevron" class="h-5 w-5" /></button>
            </div>
        @endif
    </div>
</section>
