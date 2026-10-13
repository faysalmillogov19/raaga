{{-- Accueil de la phase 1 (boutiques et cautions uniquement) : campagne d'attribution, catégories, règles, questions. --}}
@php
    $a = __('public.accueil_boutiques');
    $urlCandidater = auth()->check() ? route('boutiques.candidater') : route('inscription');
@endphp

<x-public.layout :titre="$a['titre_page']">

    {{-- 1. Hero --}}
    <section class="section bg-gradient-to-b from-vert-pale to-white">
        <div class="conteneur grid grid-cols-[minmax(0,1fr)] gap-8 lg:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)] lg:items-center lg:gap-12">
            <div>
                @if ($campagne)
                    <p class="surtitre">{{ $ouverte
                        ? __('public.accueil_boutiques.surtitre', ['campagne' => $campagne->nom, 'date' => $campagne->date_cloture->translatedFormat('j F Y')])
                        : __('public.accueil_boutiques.surtitre_fermee', ['campagne' => $campagne->nom]) }}</p>
                @endif
                <h1>{{ $a['titre'] }}</h1>
                <p class="mt-4 text-[1.1rem]">{{ $campagne ? $a['intro'] : $a['intro_vide'] }}</p>

                <div class="mt-6 flex flex-col gap-3 sm:flex-row">
                    @if ($ouverte)
                        <a href="{{ $urlCandidater }}" class="btn-primaire">{{ __('public.boutiques.candidater') }}</a>
                    @elseif (! auth()->check())
                        <a href="{{ route('inscription') }}" class="btn-primaire">{{ $a['creer_compte'] }}</a>
                    @endif
                    <a href="{{ route('boutiques') }}" class="btn-secondaire">{{ $a['voir'] }}</a>
                </div>
            </div>

            @if ($campagne)
                <dl class="carte grid grid-cols-3 gap-3 p-5 text-center">
                    <div class="flex flex-col-reverse"><dt class="text-sm text-gris">{{ $a['chiffres']['boutiques'] }}</dt>
                        <dd class="text-2xl font-extrabold text-vert-fonce">{{ format_nombre((int) $campagne->categories->sum('nombre_boutiques')) }}</dd></div>
                    <div class="flex flex-col-reverse"><dt class="text-sm text-gris">{{ $a['chiffres']['categories'] }}</dt>
                        <dd class="text-2xl font-extrabold text-vert-fonce">{{ $campagne->categories->count() }}</dd></div>
                    <div class="flex flex-col-reverse"><dt class="text-sm text-gris">{{ $a['chiffres']['jours'] }}</dt>
                        <dd class="text-2xl font-extrabold text-vert-fonce">{{ $ouverte ? max(0, (int) ceil(now()->diffInDays($campagne->date_cloture, false))) : 0 }}</dd></div>
                </dl>
            @endif
        </div>
    </section>

    {{-- 2. Comment ça marche --}}
    <section aria-labelledby="etapes-titre" class="section section-grise">
        <div class="conteneur">
            <h2 id="etapes-titre">{{ __('public.boutiques.comment_titre') }}</h2>
            <ol class="mt-4 grid gap-3 tab:grid-cols-2 lg:grid-cols-4">
                @foreach (__('public.boutiques.etapes') as $i => $etape)
                    <li class="carte p-4">
                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-vert-fonce font-bold text-white" aria-hidden="true">{{ $i + 1 }}</span>
                        <p class="mt-2 font-bold text-encre">{{ $etape['titre'] }}</p>
                        <p class="texte-carte">{{ $etape['texte'] }}</p>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    {{-- 3. Catégories et cautions --}}
    @if ($campagne)
        <section aria-labelledby="categories-titre" class="section bg-white">
            <div class="conteneur">
                <h2 id="categories-titre">{{ __('public.boutiques.categories_titre') }}</h2>
                <ul class="mt-4 grid gap-4 tab:grid-cols-2 lg:grid-cols-4">
                    @foreach ($campagne->categories as $categorie)
                        <li class="carte flex flex-col p-5">
                            @if ($categorie->site)<p class="text-sm font-semibold uppercase tracking-wide text-vert-fonce">{{ $categorie->site->nom }} · {{ $categorie->site->localite() }}</p>@endif
                            <h3 class="!text-lg">{{ $categorie->libelle }}</h3>
                            @if ($categorie->description)<p class="texte-carte mt-1">{{ $categorie->description }}</p>@endif
                            <p class="mt-2 texte-carte">{{ trans_choice('public.boutiques.nombre', $categorie->nombre_boutiques, ['n' => format_nombre($categorie->nombre_boutiques)]) }}</p>
                            <div class="mt-auto pt-3">@include('public.boutiques._montants', ['lignes' => $categorie->lignesMontants(), 'compact' => true])</div>
                        </li>
                    @endforeach
                </ul>
            </div>
        </section>
    @endif

    {{-- 4. Règles --}}
    <section aria-labelledby="regles-titre" class="section {{ $campagne ? 'section-grise' : 'bg-white' }}">
        <div class="conteneur">
            <h2 id="regles-titre">{{ $a['regles_titre'] }}</h2>
            <div class="encadres">
                @foreach ($a['regles'] as $i => $regle)
                    <div @class(['encadre', 'encadre-danger' => $i === 2, 'encadre-attention' => $i !== 2])>
                        <h3><x-icon :name="$i === 2 ? 'danger' : 'info'" class="encadre-symbole h-5 w-5" /> {{ $regle['titre'] }}</h3>
                        <p>{{ $regle['texte'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- 5. Questions --}}
    <section aria-labelledby="faq-titre" class="section {{ $campagne ? 'bg-white' : 'section-grise' }}">
        <div class="conteneur">
            <h2 id="faq-titre">{{ $a['faq_titre'] }}</h2>
            <div class="faq-liste">
                @foreach ($questions as $question)
                    <x-public.question :question="$question['q']">{{ $question['r'] }}</x-public.question>
                @endforeach
            </div>
            <a href="{{ route('faq') }}" class="lien-fort">{{ __('public.accueil.faq.toutes') }} <x-icon name="fleche" class="h-4 w-4" /></a>
        </div>
    </section>

</x-public.layout>
