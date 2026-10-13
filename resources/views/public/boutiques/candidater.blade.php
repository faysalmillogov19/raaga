{{-- Candidature : choix d'une catégorie, quatre engagements jamais pré-cochés. --}}
@php
    use App\Enums\StatutKyc;

    $kyc = $user->kycValide();
    $titre = __('public.boutiques.formulaire.titre', ['campagne' => $campagne->nom]);
    $f = __('public.boutiques.formulaire');
@endphp

<x-public.layout :titre="$titre">
    <x-public.page-entete :titre="$titre" :intro="$f['intro']" :fil="[['libelle' => __('public.boutiques.titre'), 'url' => route('boutiques')], ['libelle' => __('public.boutiques.candidater')]]" />

    <div class="conteneur max-w-3xl py-8 tab:py-12">
        @unless ($kyc)
            <x-public.alerte variante="attention" :titre="__('public.souscription.kyc.titre')" class="mb-6">
                {{ __('public.souscription.kyc.'.($user->statut_kyc ?? StatutKyc::NonSoumis)->value) }}
                <a href="{{ route('espace.verification') }}" class="font-semibold">{{ __('public.souscription.kyc.lien') }}</a>
            </x-public.alerte>
        @endunless

        @if ($errors->any())
            <x-public.alerte variante="danger" class="mb-6" role="alert">
                <ul class="space-y-1">@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
            </x-public.alerte>
        @endif

        <form method="POST" action="{{ route('boutiques.candidater.store') }}" novalidate class="space-y-6"
              x-data="{ categorie: @js(old('categorie') ? (int) old('categorie') : null), coches: [] }">
            @csrf
            <fieldset class="carte p-5">
                <legend class="sr-only">{{ $f['categorie'] }}</legend>
                <h2 class="!text-lg" aria-hidden="true">{{ $f['categorie'] }}</h2>
                <div class="mt-4 grid gap-3 tab:grid-cols-2">
                    @foreach ($campagne->categories as $cat)
                        <label class="flex min-h-[56px] cursor-pointer items-start gap-3 rounded-charte border-2 p-4"
                               :class="categorie === {{ $cat->id }} ? 'border-vert-fonce bg-vert-pale' : 'border-ligne'">
                            <input type="radio" name="categorie" value="{{ $cat->id }}" x-model.number="categorie" class="mt-1 h-5 w-5 text-vert focus:ring-0">
                            <span>
                                @if ($cat->site)<span class="block text-sm font-semibold uppercase tracking-wide text-vert-fonce">{{ $cat->site->nom }} · {{ $cat->site->commune }}</span>@endif
                                <span class="block font-bold text-encre">{{ $cat->libelle }}</span>
                                @if ($cat->description)<span class="texte-carte block">{{ $cat->description }}</span>@endif
                                <span class="mt-2 block">@include('public.boutiques._montants', ['lignes' => $cat->lignesMontants(), 'compact' => true])</span>
                            </span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <section class="carte p-5" aria-labelledby="engagements-titre">
                <div class="flex items-baseline justify-between gap-3">
                    <h2 id="engagements-titre" class="!text-lg">{{ $f['engagements'] }}</h2>
                    <p class="font-bold text-vert-fonce" aria-live="polite"><span x-text="coches.length">0</span>/4</p>
                </div>
                <p class="texte-carte">{{ $f['engagements_aide'] }}</p>
                <ul class="mt-4 space-y-3">
                    @foreach ($engagements as $document)
                        <li class="rounded-charte border border-ligne p-3">
                            <label class="flex min-h-[44px] cursor-pointer items-start gap-3">
                                <input type="checkbox" name="engagements[]" value="{{ $document->value }}" x-model="coches"
                                       class="mt-0.5 h-6 w-6 shrink-0 rounded border-2 border-gris text-vert focus:ring-0">
                                <span class="font-semibold text-encre">{{ __('public.boutiques.engagements.'.$document->value, ['version' => $campagne->version]) }}</span>
                            </label>
                            @if ($document === \App\Enums\DocumentAccepte::ReglementCampagneBoutiques)
                                <a href="{{ route('boutiques.reglement') }}" target="_blank" rel="noopener" class="ml-9 inline-flex min-h-[44px] items-center">{{ __('public.boutiques.lire_reglement') }}</a>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>

            <div>
                <button type="submit" class="btn-primaire w-full disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto"
                        :disabled="! (@js($kyc) && categorie && coches.length === 4)" disabled>
                    <span>{{ $f['continuer'] }}</span> <span>(<span x-text="coches.length">0</span>/4)</span>
                </button>
                <p class="mt-2 texte-carte">{{ $kyc ? __('public.boutiques.formulaire.delai', ['heures' => $campagne->delai_paiement_heures]) : $f['kyc_bouton'] }}</p>
            </div>
        </form>
    </div>
</x-public.layout>
