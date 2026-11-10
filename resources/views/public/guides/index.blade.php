{{--
    Rubrique « Guides » (10/11/2026) : sommaire des guides pas à pas. Source de la page statique public/guides/index.html
    (raaga:guides) ; jamais servie en production.
--}}
@php($g = __('public.guides'))
<x-public.layout :titre="$g['titre']">
    <x-public.page-entete :titre="$g['titre']" :intro="$g['intro']" :fil="[['libelle' => $g['titre']]]" />

    <div class="mx-auto max-w-6xl px-4 py-10 sm:px-6">
        <ul class="guides-liste" data-guides>
            @foreach ($guides as $guide)
                <li class="guides-carte">
                    @if (in_array('carte', \App\Support\Guides\Guides::captures($guide), true))
                        <img src="{{ \App\Support\Guides\Guides::image($guide['slug'], 'carte') }}" alt="" class="guides-vignette" loading="lazy">
                    @endif
                    <div class="guides-carte-texte">
                        <h2>{{ $guide['titre'] }}</h2>
                        <p>{{ $guide['resume'] }}</p>
                        <p class="guides-meta">{{ __('public.guides.etapes', ['n' => count($guide['etapes'])]) }}</p>
                        <a href="{{ url('guides/'.$guide['slug'].'/index.html') }}" class="btn-plein guides-ouvrir">{{ $g['ouvrir'] }}</a>
                    </div>
                </li>
            @endforeach
        </ul>
    </div>
</x-public.layout>
