{{-- Reçu de caution, ou d'avance ($avance), d'une réservation de boutique. En-tête commun : armoiries, logo, mentions légales (paramètres). --}}
@php
    $c = $candidature;
    $emis = $paiement->date_confirmation->format('d/m/Y');
    $avance = $avance ?? false;
    $intitule = $avance ? 'Reçu d’avance' : 'Reçu de caution';
@endphp

<x-document-pdf :titre="$intitule.' '.$numero" :pied="'Reçu n° '.$numero.' · réservation '.$c->reference.' · émis le '.$emis">
    <p class="surtitre">Réservation des boutiques · {{ $c->campagne->nom }}</p>
    <h1>{{ $intitule }}</h1>
    <p>Reçu n° <strong>{{ $numero }}</strong>, émis le {{ $emis }}</p>

    <table class="donnees">
        <tr><th>Titulaire</th><td>{{ $c->user->name }}@if ($c->user->telephone) · {{ \App\Support\Telephone::masquer($c->user->telephone) }}@endif</td></tr>
        <tr><th>Référence de la réservation</th><td>{{ $c->reference }}</td></tr>
        <tr><th>Boutique</th><td><strong>{{ $c->boutique?->code }}</strong>@if ($c->boutique?->type) ({{ $c->boutique->type->libelle }}@if ($c->boutique->surface), {{ format_nombre((float) $c->boutique->surface, 2) }} m²@endif)@endif</td></tr>
        {{-- Caution et avance : deux lignes distinctes, chacune avec sa mention. --}}
        @foreach ($c->lignesMontants() as $ligne)
            <tr><th>{{ $ligne['libelle'] }}</th><td><strong>{{ format_fcfa($ligne['montant']) }}</strong><br>{{ $ligne['mention'] }}</td></tr>
        @endforeach
        <tr><th>{{ __('public.boutiques.montants.total') }}</th><td><strong>{{ format_fcfa($paiement->montant) }}</strong></td></tr>
        <tr><th>Moyen de paiement</th><td>{{ $paiement->canal->label() }}</td></tr>
        <tr><th>{{ __('public.paiement.compte_credite') }}</th><td>{{ $paiement->compte_destination->label() }}</td></tr>
        <tr><th>Référence du paiement</th><td>{{ $paiement->reference }}</td></tr>
        <tr><th>Date du paiement</th><td>{{ $paiement->date_confirmation->format('d/m/Y à H\hi') }}</td></tr>
        <tr><th>Paiement réglé</th><td><strong>{{ $avance ? 'Avance' : 'Caution' }}</strong></td></tr>
        <tr><th>Statut</th><td>{{ $c->libelleStatut() }}</td></tr>
    </table>

    @if ($avance)
        <p>L’avance payée est imputée sur les premiers loyers ; la boutique est attribuée au titulaire après la signature du contrat.</p>
    @else
        <p>La caution payée réserve la boutique au nom du titulaire ; elle est attribuée après le paiement de l’avance et la signature du contrat.</p>
    @endif

    <div class="encadre">
        <strong>L’achat d’actions ne donne aucun droit ni priorité pour obtenir une boutique.</strong><br>
        La caution n’est pas un achat d’actions et ne donne aucun droit sur les actions de Raaga.
    </div>
</x-document-pdf>
