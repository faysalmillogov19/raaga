{{-- Règlement de la campagne d'attribution des boutiques, généré à partir de ses paramètres confirmés. --}}
@php
    $c = $campagne;
    $titre = __('public.boutiques.reglement.titre');
@endphp

<x-public.layout :titre="$titre">
    <x-public.page-entete :titre="$titre" :fil="[['libelle' => __('public.boutiques.titre'), 'url' => route('boutiques')], ['libelle' => $titre]]" />

    <div class="conteneur max-w-3xl py-8 tab:py-12">
        <article class="carte space-y-4 p-5 leading-relaxed tab:p-8">
            @include('documents._entete')
            <header>
                <p class="surtitre">Attribution des boutiques du marché</p>
                <h2>Règlement — {{ $c->nom }}</h2>
                <p class="texte-carte">Version {{ $c->version }} · {{ $c->statut->label() }}</p>
            </header>

            <section><h3>Article 1 — Objet</h3>
                <p>La présente campagne attribue des boutiques du marché Raaga par tirage au sort, entre les citoyens qui ont candidaté et payé la caution de la catégorie choisie.</p></section>

            <section><h3>Article 2 — Lots, cautions et avances</h3>
                <ul class="list-disc pl-5">
                    @foreach ($c->categories as $cat)
                        <li><strong>{{ $cat->intitule() }}</strong>@if ($cat->description) ({{ $cat->description }})@endif : {{ format_nombre($cat->nombre_boutiques) }} boutique(s)@if ($cat->loyer_mensuel), loyer mensuel de {{ format_fcfa($cat->loyer_mensuel) }}@endif.
                            @foreach ($cat->lignesMontants() as $ligne)<br>{{ $ligne['libelle'] }} : {{ format_fcfa($ligne['montant']) }} — {{ $ligne['mention'] }}@endforeach</li>
                    @endforeach
                </ul></section>

            <section><h3>Article 3 — Candidature</h3>
                <p>Les candidatures sont reçues du {{ $c->date_ouverture->format('d/m/Y à H\hi') }} au {{ $c->date_cloture->format('d/m/Y à H\hi') }} (heure de Ouagadougou).
                    Une identité vérifiée est exigée. Chaque personne peut déposer plusieurs candidatures, sur un ou plusieurs sites ; chaque candidature exige le paiement de sa caution
                    dans les {{ $c->delai_paiement_heures }} heures, sinon elle expire.
                    @if ($c->max_boutiques_par_personne) Une même personne ne peut pas obtenir plus de {{ $c->max_boutiques_par_personne }} boutique(s) dans cette campagne. @endif</p></section>

            <section><h3>Article 4 — Tirage au sort</h3>
                <p>Après la clôture, un premier tirage au sort est réalisé dans chaque lot (site et type de boutique), parmi les candidatures dont la caution est payée.
                    Les premiers candidats tirés, dans la limite du nombre de boutiques du lot, sont retenus@if ($c->max_boutiques_par_personne), sans qu’une même personne obtienne plus de {{ $c->max_boutiques_par_personne }} boutique(s) : une candidature dont le titulaire a atteint ce nombre est écartée et la suivante prend sa place@endif.
                    Un second tirage au sort attribue ensuite à chaque candidat retenu une boutique précise du lot. Les deux tirages sont enregistrés et vérifiables.</p></section>

            <section><h3>Article 5 — Caution des candidats non retenus</h3>
                <p>Les modalités de restitution de la caution des candidats non retenus seront communiquées avant le tirage au sort.</p></section>

            <section class="encadre encadre-danger"><h3>Article 6 — Indépendance des actions</h3>
                <p>L’achat d’actions <strong>ne donne aucun droit ni aucune priorité</strong> pour obtenir une boutique. La candidature et la caution ne donnent aucun droit sur les actions de Raaga.</p></section>
        </article>
        <a href="{{ route('boutiques') }}" class="btn-secondaire mt-6">{{ __('public.boutiques.titre') }}</a>
    </div>
</x-public.layout>
