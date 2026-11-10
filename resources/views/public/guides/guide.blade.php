{{--
    Guide pas à pas (10/11/2026) : étapes numérotées, ce que fait le citoyen, ce qui se passe ensuite, captures (données
    fictives). Source de la page statique public/guides/{guide}/index.html (raaga:guides) ; jamais servie en production.
--}}
@php
    $g = __('public.guides');
    $dossier = \App\Support\Guides\Guides::dossier($guide['slug']);
    $dates = array_filter(array_map(fn (string $f) => @filemtime($dossier.'/'.$f.'.jpg') ?: null, \App\Support\Guides\Guides::captures($guide)));
@endphp
<x-public.layout :titre="$guide['titre']">
    <x-public.page-entete :titre="$guide['titre']" :intro="$guide['resume']"
                          :fil="[['libelle' => $g['titre'], 'url' => url('guides/index.html')], ['libelle' => $guide['titre']]]" />

    <div class="guide-cadre mx-auto max-w-6xl px-4 py-10 sm:px-6" data-guide="{{ $guide['slug'] }}">
        <nav class="guide-sommaire" aria-label="{{ $g['sommaire'] }}">
            <p class="guide-sommaire-titre">{{ $g['sommaire'] }}</p>
            <ol>
                @foreach ($guide['etapes'] as $i => $etape)
                    <li><a href="#{{ $etape['id'] }}"><span>{{ $i + 1 }}</span>{{ $etape['titre'] }}</a></li>
                @endforeach
            </ol>
            <a href="{{ url('guides/index.html') }}" class="guide-retour">{{ $g['retour'] }}</a>
        </nav>

        <div class="guide-contenu">
            <p class="guide-pour-qui"><span class="font-semibold">{{ $g['pour_qui'] }} :</span> {{ $guide['public'] }}</p>

            @foreach ($guide['etapes'] as $i => $etape)
                <section class="guide-etape" id="{{ $etape['id'] }}" data-etape="{{ $etape['id'] }}">
                    <p class="guide-numero">{{ __('public.guides.etape', ['n' => $i + 1]) }}</p>
                    <h2>{{ $etape['titre'] }}</h2>
                    <div class="guide-colonnes">
                        <div>
                            <h3>{{ $g['vous_faites'] }}</h3>
                            <ol class="guide-gestes">
                                @foreach ($etape['gestes'] as $geste)
                                    <li>{{ $geste }}</li>
                                @endforeach
                            </ol>
                        </div>
                        <div class="guide-resultat">
                            <h3>{{ $g['resultat'] }}</h3>
                            <p>{{ $etape['resultat'] }}</p>
                        </div>
                    </div>
                    <div class="guide-captures">
                        @foreach ($etape['captures'] as $capture)
                            @php($src = \App\Support\Guides\Guides::image($guide['slug'], $capture['fichier']))
                            <figure>
                                <a href="{{ $src }}" target="_blank" rel="noopener" title="{{ $g['agrandir'] }}">
                                    <img src="{{ $src }}" alt="{{ $capture['legende'] }}" loading="lazy">
                                </a>
                                <figcaption>{{ $capture['legende'] }}</figcaption>
                            </figure>
                        @endforeach
                    </div>
                </section>
            @endforeach

            @if (! empty($guide['aSavoir']))
                <x-public.alerte variante="info" :titre="$g['a_savoir']" class="mt-8" data-a-savoir>
                    <ul class="list-disc space-y-1 pl-5">
                        @foreach ($guide['aSavoir'] as $point)
                            <li>{{ $point }}</li>
                        @endforeach
                    </ul>
                </x-public.alerte>
            @endif

            @if ($dates)
                <p class="guide-maj">{{ __('public.guides.mise_a_jour', ['date' => date('d/m/Y', max($dates))]) }}</p>
            @endif
        </div>
    </div>
</x-public.layout>
