{{-- Reçu de souscription (actions B). Armoiries, logo et mentions légales : en-tête commun (paramètres de la plateforme). --}}
@php
    $s = $souscription;
    $total = $paiement->montant + $paiement->frais;
    $emis = $paiement->date_confirmation->format('d/m/Y');
@endphp

<x-document-pdf :titre="'Reçu '.$numero" :pied="'Reçu n° '.$numero.' · souscription '.$s->reference.' · émis le '.$emis">
    <p class="surtitre">{{ $s->tranche->nom }}</p>
    <h1>Reçu de souscription</h1>
    <p>Reçu n° <strong>{{ $numero }}</strong>, émis le {{ $emis }}</p>

    <table class="donnees">
        <tr><th>Souscripteur</th><td>{{ $s->user->name }}@if ($s->user->telephone) · {{ \App\Support\Telephone::masquer($s->user->telephone) }}@endif</td></tr>
        <tr><th>Référence de la souscription</th><td>{{ $s->reference }}</td></tr>
        <tr><th>Nombre d’actions demandées</th><td>{{ format_nombre($s->nb_actions_demandees) }}</td></tr>
        <tr><th>Prix d’une action</th><td>{{ format_fcfa($s->valeur_unitaire) }}</td></tr>
        <tr><th>Montant des actions</th><td>{{ format_fcfa($paiement->montant) }}</td></tr>
        <tr><th>Frais de souscription</th><td>{{ format_fcfa($paiement->frais) }}</td></tr>
        <tr><th>Total payé</th><td><strong>{{ format_fcfa($total) }}</strong></td></tr>
        <tr><th>Moyen de paiement</th><td>{{ $paiement->canal->label() }}</td></tr>
        <tr><th>{{ __('public.paiement.compte_credite') }}</th><td>{{ $paiement->compte_destination->label() }}</td></tr>
        <tr><th>Référence du paiement</th><td>{{ $paiement->reference }}</td></tr>
        <tr><th>Date du paiement</th><td>{{ $paiement->date_confirmation->format('d/m/Y à H\hi') }}</td></tr>
        <tr><th>Statut</th><td>{{ $s->statut === \App\Enums\StatutSouscription::Allouee ? __('public.recu.allouee') : __('public.paiement.attente_allocation') }}</td></tr>
    </table>

    <p>Le nombre d’actions définitivement attribuées sera connu à la clôture de la tranche, selon le règlement accepté (version {{ $s->tranche->version }}).
        Les sommes payées pour des actions non attribuées sont remboursées.</p>

    <div class="encadre">
        <strong>L’achat d’actions ne donne aucun droit ni priorité pour obtenir une boutique.</strong><br>
        Le rendement n’est pas garanti.
    </div>
</x-document-pdf>
