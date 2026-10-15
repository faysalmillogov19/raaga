{{-- Avis de distribution de dividendes (actions A) d'une institution : brut, retenue, net, compte de versement. --}}
<x-document-pdf :titre="'Avis de distribution, exercice '.$distribution->exercice" :pied="'Dividendes des actions A · exercice '.$distribution->exercice.' · émis le '.now()->format('d/m/Y')">
    <p class="surtitre">Actions A · exercice {{ $distribution->exercice }}</p>
    <h1>Avis de distribution de dividendes</h1>

    <p>À l’attention de <strong>{{ $institution->denomination }}</strong>.</p>
    <p>L’assemblée générale du {{ $distribution->ag_date->translatedFormat('j F Y') }} (procès-verbal n° {{ $distribution->ag_pv_numero }}) a décidé la distribution
        d’un dividende aux actions A, au profit des actionnaires inscrits au registre des actions A le {{ $distribution->date_arrete->translatedFormat('j F Y') }}.</p>

    <table class="donnees">
        <tr><th>Actions A détenues à la date d’arrêté</th><td>{{ format_nombre($ligne->nbActions) }}</td></tr>
        <tr><th>Dividende brut</th><td>{{ format_fcfa($ligne->brut) }}</td></tr>
        <tr><th>Retenue fiscale (IRVM)</th><td>{{ format_fcfa($ligne->retenue) }}</td></tr>
        <tr><th>Dividende net</th><td><strong>{{ format_fcfa($ligne->net) }}</strong></td></tr>
        <tr><th>Date de mise en paiement</th><td>{{ $distribution->date_mise_en_paiement->translatedFormat('j F Y') }}</td></tr>
        <tr><th>Mode de versement</th><td>Virement sur le compte vérifié de l’institution</td></tr>
        <tr><th>Compte de versement</th><td>
            @if ($institution->rib_numero)
                {{ $institution->rib_titulaire }} · {{ $institution->rib_banque }}<br><strong>{{ $institution->rib_numero }}</strong>
            @else
                RIB à compléter dans l’espace investisseur (rubrique « Mon organisation »).
            @endif
        </td></tr>
    </table>

    <div class="encadre">Aucun montant de dividende n’est garanti pour les exercices suivants : il dépend des bénéfices réellement distribuables et des décisions de l’assemblée générale.</div>
</x-document-pdf>
