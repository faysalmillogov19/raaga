{{-- Avis d'appel de fonds (actions A) : montant, échéance, compte séquestre, référence de virement obligatoire. --}}
@php
    $sequestre = array_filter(\App\Support\CompteSequestre::actuel());
    $convention = $appel->convention;
    $institution = $appel->institution;
@endphp

<x-document-pdf :titre="'Avis d’appel de fonds — '.$appel->reference_virement" :pied="'Convention '.$convention->reference.' · appel n° '.$appel->numero.' · émis le '.now()->format('d/m/Y')">
    <p class="surtitre">Actions A · convention {{ $convention->reference }}</p>
    <h1>Avis d’appel de fonds n° {{ $appel->numero }}</h1>

    <p>À l’attention de <strong>{{ $institution->denomination }}</strong>.</p>
    <p>Conformément à l’échéancier de la convention de souscription, nous vous prions de bien vouloir verser le montant ci-dessous par virement.</p>

    <table class="donnees">
        <tr><th>Objet</th><td>{{ $appel->libelle }}</td></tr>
        <tr><th>Montant appelé</th><td><strong>{{ format_fcfa($appel->montant) }}</strong></td></tr>
        @if ($appel->montant_recu > 0)
            <tr><th>Déjà reçu</th><td>{{ format_fcfa($appel->montant_recu) }} — reste à verser : <strong>{{ format_fcfa($appel->resteAPayer()) }}</strong></td></tr>
        @endif
        <tr><th>Date limite de réception</th><td><strong>{{ $appel->echeance->translatedFormat('j F Y') }}</strong></td></tr>
        <tr><th>Compte séquestre bénéficiaire</th><td>
            @if ($sequestre)
                {{ $sequestre['titulaire'] ?? '' }}@if (isset($sequestre['banque'])) — {{ $sequestre['banque'] }}@endif<br>
                @isset($sequestre['numero'])<strong>{{ $sequestre['numero'] }}</strong>@endisset
            @else
                Communiqué par votre interlocuteur dédié{{ $institution->interlocuteur_nom ? ' ('.$institution->interlocuteur_nom.')' : '' }}.
            @endif
        </td></tr>
    </table>

    <div class="encadre">
        <strong>Référence de virement obligatoire : {{ $appel->reference_virement }}</strong><br>
        Indiquez-la dans le libellé du virement : elle permet de rapprocher votre paiement de cet appel de fonds. Après le virement, déclarez-le dans votre espace investisseur (date, montant, référence, avis de virement).
    </div>

    <p>Total engagé par la convention : {{ format_fcfa($convention->montant_engage) }} pour {{ format_nombre($convention->nb_actions) }} actions A.</p>
</x-document-pdf>
