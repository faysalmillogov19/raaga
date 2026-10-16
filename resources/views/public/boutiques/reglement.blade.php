{{-- Règlement de la campagne de réservation des boutiques (premier arrivé, premier servi), généré à partir de ses paramètres confirmés. --}}
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
                <p class="surtitre">Réservation des boutiques du marché</p>
                <h2>Règlement · {{ $c->nom }}</h2>
                <p class="texte-carte">Version {{ $c->version }} · {{ $c->statut->label() }}</p>
            </header>

            <section><h3>Article 1 : Objet</h3>
                <p>La présente campagne permet de réserver une boutique précise du marché Raaga, au premier arrivé, premier servi : aucune priorité, aucun tirage au sort.
                    Sites concernés : {{ $c->sitesConcernes()->map(fn ($s) => $s->nom)->implode(', ') }}.</p></section>

            <section><h3>Article 2 : Période</h3>
                <p>Les réservations sont reçues du {{ $c->date_ouverture->format('d/m/Y à H\hi') }} au {{ $c->date_cloture->format('d/m/Y à H\hi') }} (heure de Ouagadougou).
                    Aucune réservation n’est enregistrée avant l’ouverture ni après la fermeture.</p></section>

            <section><h3>Article 3 : Réservation</h3>
                <p>Le citoyen, titulaire d’un compte dont le téléphone est vérifié, choisit une boutique disponible sur le plan, joint sa CNIB, son passeport ou son NIF (avec le RCCM pour une entreprise) et confirme.
                    La boutique est alors pré-réservée à son nom. Le premier est celui dont la confirmation est enregistrée en premier par le serveur.
                    Une même personne, identifiée par son compte et par le numéro de sa pièce, ne peut pas réserver plus de {{ $c->max_boutiques_par_personne }} boutique(s) dans cette campagne.</p></section>

            <section><h3>Article 4 : Caution et avance</h3>
                <p>La réservation est confirmée par le paiement de la caution ({{ __('public.boutiques.montants.mention_caution') }}) dans un délai de {{ $c->libelleDelaiPaiement() }} ; sans paiement dans ce délai,
                    la boutique redevient disponible. Le délai est suspendu tant qu’une preuve de paiement attend son contrôle. Caution et avance sont calculées pour chaque boutique selon la grille tarifaire confirmée de son site.
                    L’avance est due avant la signature du contrat, au plus tard {{ \App\Models\ParametresPaiements::libelleEnVigueur('avance') }} après le passage de la boutique en « réservée » ; la date limite figure dans le dossier de réservation. La boutique est attribuée quand l’avance est payée et le contrat signé.</p></section>

            <section><h3>Article 5 : Vérification d’identité</h3>
                <p>Les pièces jointes sont vérifiées. Une identité reconnue douteuse peut entraîner l’annulation de la réservation : la boutique redevient disponible et la caution déjà payée est remboursée.</p></section>

            <section class="encadre encadre-danger"><h3>Article 6 : Indépendance des actions</h3>
                <p>L’achat d’actions <strong>ne donne aucun droit ni aucune priorité</strong> pour obtenir une boutique. La réservation et la caution ne donnent aucun droit sur les actions de Raaga.</p></section>
        </article>
        <a href="{{ route('boutiques') }}" class="btn-secondaire mt-6">{{ __('public.boutiques.titre') }}</a>
    </div>
</x-public.layout>
