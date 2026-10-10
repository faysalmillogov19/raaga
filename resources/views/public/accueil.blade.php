<x-public.layout :titre="__('public.accueil.titre_page')">

    {{-- 1. Hero (dégradé vert pâle → blanc) --}}
    <section class="section bg-gradient-to-b from-vert-pale to-white">
        <div class="conteneur grid grid-cols-[minmax(0,1fr)] gap-8 lg:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)] lg:items-center lg:gap-12">
            <div>
                @if ($etat)
                    <p class="surtitre">{{ __('public.accueil.hero.surtitre', ['tranche' => $etat->nomTranche, 'date' => $etat->dateCloture->translatedFormat('j F Y')]) }}</p>
                    <h1>{{ __('public.accueil.hero.titre', ['prix' => format_fcfa($etat->valeurUnitaire)]) }}</h1>
                    <p class="mt-4 text-[1.1rem]">
                        {{ __('public.accueil.hero.texte', [
                            'prix' => format_fcfa($etat->valeurUnitaire),
                            'plafond' => $etat->plafondActionsParPersonne === null
                                ? __('public.accueil.hero.sans_plafond')
                                : __('public.accueil.hero.avec_plafond', ['plafond' => format_nombre($etat->plafondActionsParPersonne)]),
                        ]) }}
                    </p>
                @else
                    <p class="surtitre">{{ __('public.accueil.hero.surtitre_fermee') }}</p>
                    <h1>{{ __('public.accueil.hero.titre_ferme') }}</h1>
                    <p class="mt-4 text-[1.1rem]">{{ __('public.accueil.hero.texte_ferme') }}</p>
                @endif

                <div class="mt-6 flex flex-col gap-3 sm:flex-row">
                    @if ($etat)
                        <a href="{{ $urlSouscrire }}" class="btn-primaire">{{ __('public.accueil.hero.souscrire') }}</a>
                    @endif
                    <a href="{{ route('offre') }}" class="btn-secondaire">{{ __('public.accueil.hero.comprendre') }}</a>
                </div>
            </div>

            @if ($etat)
                <x-public.jauge-collecte :etat="$etat" />
            @endif
        </div>
    </section>

    {{-- 2. Chiffres clés (blanc) --}}
    <section aria-labelledby="chiffres-titre" class="section bg-white">
        <div class="conteneur">
            <h2 id="chiffres-titre" class="sr-only">{{ __('public.accueil.chiffres.titre') }}</h2>
            @php
                $chiffres = array_values(array_filter([
                    $etat ? [format_fcfa($etat->valeurUnitaire), __('public.accueil.chiffres.prix')] : null,
                    $etat
                        ? ($etat->plafondActionsParPersonne === null
                            ? [__('public.accueil.chiffres.aucun_plafond'), __('public.accueil.chiffres.aucun_plafond_detail')]
                            : [__('public.accueil.chiffres.plafond', ['n' => format_nombre($etat->plafondActionsParPersonne)]), __('public.accueil.chiffres.plafond_detail')])
                        : null,
                    $projet['nombreBoutiques'] !== null ? [format_nombre($projet['nombreBoutiques']), __('public.accueil.chiffres.boutiques_detail')] : null,
                    $projet['coutTotalProjet'] !== null ? [format_md($projet['coutTotalProjet']), __('public.accueil.chiffres.cout_detail')] : null,
                ]));
            @endphp
            <dl class="chiffres-cles" @if (count($chiffres) < 4) style="grid-template-columns: repeat({{ count($chiffres) }}, 1fr)" @endif>
                @foreach ($chiffres as [$valeur, $libelle])
                    <div class="flex flex-col-reverse">
                        <dt>{{ $libelle }}</dt>
                        <dd>{{ $valeur }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    </section>

    {{-- 3. Souscrire en 4 étapes (gris) --}}
    <section aria-labelledby="etapes-titre" class="section section-grise">
        <div class="conteneur">
            <p class="surtitre">{{ __('public.accueil.etapes.surtitre') }}</p>
            <h2 id="etapes-titre">{{ __('public.accueil.etapes.titre') }}</h2>
            <p class="section-intro">{{ __('public.accueil.etapes.intro') }}</p>
            <ol class="etapes">
                @foreach (__('public.accueil.etapes.liste') as $etape)
                    <li class="etape">
                        <span class="etape-num" aria-hidden="true">{{ $loop->iteration }}</span>
                        <h3><span class="sr-only">{{ $loop->iteration }}. </span>{{ $etape['titre'] }}</h3>
                        <p class="texte-carte">{{ $etape['texte'] }}</p>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    {{-- 4. Deux règles importantes (blanc) --}}
    <section aria-labelledby="regles-titre" class="section bg-white">
        <div class="conteneur">
            <p class="surtitre">{{ __('public.accueil.regles.surtitre') }}</p>
            <h2 id="regles-titre">{{ __('public.accueil.regles.titre') }}</h2>
            <div class="encadres">
                <div class="encadre encadre-danger">
                    <h3><x-icon name="danger" class="encadre-symbole h-5 w-5" /> {{ __('public.accueil.regles.boutique_titre') }}</h3>
                    <p>{!! __('public.accueil.regles.boutique_texte') !!}</p>
                </div>
                <div class="encadre encadre-attention">
                    <h3><x-icon name="info" class="encadre-symbole h-5 w-5" /> {{ __('public.accueil.regles.rendement_titre') }}</h3>
                    <p>{!! __('public.accueil.regles.rendement_texte') !!}</p>
                </div>
            </div>
        </div>
    </section>

    {{-- 5. Qui finance Raaga ? (gris) --}}
    @include('public.partials.financement', ['projet' => $projet])

    {{-- 6. Actualités (blanc) --}}
    @if ($actualites)
        <section aria-labelledby="actualites-titre" class="section bg-white">
            <div class="conteneur">
                <p class="surtitre">{{ __('public.accueil.actualites.surtitre') }}</p>
                <h2 id="actualites-titre">{{ __('public.accueil.actualites.titre') }}</h2>
                <ul class="actus">
                    @foreach ($actualites as $actualite)
                        <li class="actu">
                            <span class="actu-tag">{{ $actualite['etiquette'] }}</span>
                            <time datetime="{{ $actualite['date']->toDateString() }}" class="actu-date">{{ $actualite['date']->translatedFormat('j F Y') }}</time>
                            <h3>{{ $actualite['titre'] }}</h3>
                            <p class="texte-carte">{{ $actualite['resume'] }}</p>
                            <a href="{{ route('actualites.show', $actualite['slug']) }}" class="lien-fort">
                                {{ __('public.accueil.actualites.lire') }} <x-icon name="fleche" class="h-4 w-4" />
                                <span class="sr-only">{{ __('public.accueil.actualites.lire_sr', ['titre' => $actualite['titre']]) }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        </section>
    @endif

    {{-- 7. FAQ (gris) --}}
    <section aria-labelledby="faq-titre" class="section section-grise">
        <div class="conteneur">
            <p class="surtitre">{{ __('public.accueil.faq.surtitre') }}</p>
            <h2 id="faq-titre">{{ __('public.accueil.faq.titre') }}</h2>
            <div class="faq-liste">
                @foreach (__('public.accueil.faq.questions') as $question)
                    <x-public.question :question="$question['q']">{{ $question['r'] }}</x-public.question>
                @endforeach
            </div>
            <a href="{{ route('faq') }}" class="lien-fort">{{ __('public.accueil.faq.toutes') }} <x-icon name="fleche" class="h-4 w-4" /></a>
        </div>
    </section>

</x-public.layout>
