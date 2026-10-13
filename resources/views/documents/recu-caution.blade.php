{{-- Reçu de caution d'une candidature aux boutiques. En-tête commun : armoiries, logo, mentions légales (paramètres). --}}
@php
    $c = $candidature;
    $emis = $paiement->date_confirmation->format('d/m/Y');
@endphp

<x-document-pdf :titre="'Reçu de caution '.$numero" :pied="'Reçu n° '.$numero.' · candidature '.$c->reference.' · émis le '.$emis">
    <p class="surtitre">Attribution des boutiques · {{ $c->campagne->nom }}</p>
    <h1>Reçu de caution</h1>
    <p>Reçu n° <strong>{{ $numero }}</strong> — émis le {{ $emis }}</p>

    <table class="donnees">
        <tr><th>Candidat</th><td>{{ $c->user->name }}@if ($c->user->telephone) — {{ \App\Support\Telephone::masquer($c->user->telephone) }}@endif</td></tr>
        <tr><th>Référence de la candidature</th><td>{{ $c->reference }}</td></tr>
        <tr><th>Catégorie de boutique</th><td>{{ $c->categorie->intitule() }}@if ($c->categorie->description) ({{ $c->categorie->description }})@endif</td></tr>
        @if ($c->boutique)<tr><th>Boutique attribuée</th><td><strong>{{ $c->boutique->reference() }}</strong></td></tr>@endif
        <tr><th>Caution payée</th><td><strong>{{ format_fcfa($paiement->montant) }}</strong></td></tr>
        <tr><th>Moyen de paiement</th><td>{{ __('public.paiement.canaux.'.$paiement->canal->value) }}</td></tr>
        <tr><th>Référence du paiement</th><td>{{ $paiement->reference }}</td></tr>
        <tr><th>Date du paiement</th><td>{{ $paiement->date_confirmation->format('d/m/Y à H\hi') }}</td></tr>
        <tr><th>Statut</th><td>{{ $c->statut->label() }}</td></tr>
    </table>

    <p>Les boutiques sont attribuées par tirage au sort après la clôture des candidatures, dans chaque catégorie, parmi les cautions payées.
        Le paiement de la caution ne garantit pas l’obtention d’une boutique.</p>

    <div class="encadre">
        <strong>L’achat d’actions ne donne aucun droit ni priorité pour obtenir une boutique.</strong><br>
        La caution n’est pas un achat d’actions et ne donne aucun droit sur les actions de Raaga.
    </div>
</x-document-pdf>
