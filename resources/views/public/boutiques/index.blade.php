{{-- Boutiques du marché : campagne d'attribution (catégories, cautions, tirage au sort), indépendante des actions. --}}
@php($titre = __('public.boutiques.titre'))

<x-public.layout :titre="$titre">
    <x-public.page-entete :titre="$titre" :intro="__('public.boutiques.intro')" :fil="[['libelle' => $titre]]" />

    <div class="conteneur py-8 tab:py-12">
        <x-public.alerte variante="attention" :titre="__('public.boutiques.independance_titre')" class="mb-8">
            {{ __('public.boutiques.independance') }}
        </x-public.alerte>

        @if (! $campagne)
            <x-etat-vide icone="boutiques" :titre="__('public.boutiques.vide_titre')" :lien="route('faq')" :libelle-lien="__('public.etats_vides.liens.faq')">
                {{ __('public.boutiques.vide') }}
            </x-etat-vide>
        @else
            <section aria-labelledby="campagne-titre">
                <h2 id="campagne-titre">{{ $campagne->nom }}</h2>
                <p class="mt-1 font-semibold text-vert-fonce">{{ __('public.boutiques.periode', ['debut' => $campagne->date_ouverture->format('d/m/Y'), 'fin' => $campagne->date_cloture->format('d/m/Y')]) }}</p>
                @if ($campagne->description)
                    <p class="mt-3 max-w-3xl whitespace-pre-line leading-relaxed">{{ $campagne->description }}</p>
                @endif
            </section>

            <section class="mt-8" aria-labelledby="comment-titre">
                <h2 id="comment-titre" class="!text-xl">{{ __('public.boutiques.comment_titre') }}</h2>
                <ol class="mt-4 grid gap-3 tab:grid-cols-2 lg:grid-cols-4">
                    @foreach (__('public.boutiques.etapes') as $i => $etape)
                        <li class="carte p-4">
                            <span class="flex h-8 w-8 items-center justify-center rounded-full bg-vert-fonce font-bold text-white" aria-hidden="true">{{ $i + 1 }}</span>
                            <p class="mt-2 font-bold text-encre">{{ $etape['titre'] }}</p>
                            <p class="texte-carte">{{ $etape['texte'] }}</p>
                        </li>
                    @endforeach
                </ol>
            </section>

            <section class="mt-8" aria-labelledby="categories-titre">
                <h2 id="categories-titre" class="!text-xl">{{ __('public.boutiques.categories_titre') }}</h2>
                <ul class="mt-4 grid gap-4 tab:grid-cols-2 lg:grid-cols-3">
                    @foreach ($campagne->categories as $categorie)
                        <li class="carte flex flex-col p-5">
                            @if ($categorie->site)<p class="text-sm font-semibold uppercase tracking-wide text-vert-fonce">{{ $categorie->site->nom }} · {{ $categorie->site->commune }}</p>@endif
                            <h3 class="!text-lg">{{ $categorie->libelle }}</h3>
                            @if ($categorie->description)
                                <p class="texte-carte mt-1">{{ $categorie->description }}</p>
                            @endif
                            <p class="mt-2 texte-carte">{{ trans_choice('public.boutiques.nombre', $categorie->nombre_boutiques, ['n' => format_nombre($categorie->nombre_boutiques)]) }}</p>
                            @if ($categorie->loyer_mensuel)
                                <p class="texte-carte">{{ __('public.boutiques.montants.loyer') }} : {{ format_fcfa($categorie->loyer_mensuel) }}</p>
                            @endif
                            <div class="mt-auto pt-3">@include('public.boutiques._montants', ['lignes' => $categorie->lignesMontants(), 'compact' => true])</div>
                        </li>
                    @endforeach
                </ul>
            </section>

            <div class="mt-8 flex flex-col gap-3 sm:flex-row sm:items-center">
                @if ($ouverte)
                    <a href="{{ route('boutiques.candidater') }}" class="btn-primaire">{{ $candidature ? __('public.boutiques.candidater_encore') : __('public.boutiques.candidater') }}</a>
                @endif
                @if ($candidature)
                    <a href="{{ route('espace', ['onglet' => 'boutiques']) }}" class="btn-secondaire">{{ __('public.boutiques.mes_candidatures') }}</a>
                @endif
                <a href="{{ route('boutiques.reglement') }}" class="btn-secondaire">{{ __('public.boutiques.lire_reglement') }}</a>
            </div>
            @if ($campagne->max_boutiques_par_personne)
                <p class="mt-3 texte-carte">{{ __('public.boutiques.plafond', ['n' => $campagne->max_boutiques_par_personne]) }}</p>
            @endif
            @if ($ouverte && ! $candidature)
                <p class="mt-3 texte-carte">{{ __('public.boutiques.kyc_requis') }}</p>
            @elseif (! $ouverte)
                <x-public.alerte variante="info" :titre="__('public.boutiques.fermee_titre')" class="mt-6">{{ __('public.boutiques.fermee') }}</x-public.alerte>
            @endif
        @endif

        @if ($antiFraude = plateforme('messages.messageAntiFraude'))
            <x-public.alerte variante="attention" class="mt-8">{{ $antiFraude }}</x-public.alerte>
        @endif
    </div>
</x-public.layout>
