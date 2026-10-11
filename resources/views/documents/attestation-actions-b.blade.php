{{-- Attestation d'actionnaire (actions B) d'un citoyen, établie à partir de sa ligne du registre après allocation. --}}
@php($emise = now()->format('d/m/Y'))

<x-document-pdf :titre="'Attestation d’actionnaire — '.$ligne->numero_certificat"
                :pied="'Certificat '.$ligne->numero_certificat.' · registre des actions B · émise le '.$emise">
    <p class="surtitre">Registre des actions B · {{ $ligne->tranche->nom }}</p>
    <h1>Attestation d’actionnaire</h1>

    <p>La société atteste que la personne ci-dessous est inscrite au registre des actions B pour les actions suivantes :</p>

    <table class="donnees">
        <tr><th>Actionnaire</th><td><strong>{{ $ligne->user->name }}</strong></td></tr>
        <tr><th>Numéro de certificat</th><td>{{ $ligne->numero_certificat }}</td></tr>
        <tr><th>Actions inscrites</th><td><strong>{{ format_nombre($ligne->nb_actions) }} actions B</strong></td></tr>
        <tr><th>Numéros des actions</th><td>{{ format_nombre($ligne->numero_action_debut) }} à {{ format_nombre($ligne->numero_action_fin) }}</td></tr>
        <tr><th>Date d’inscription</th><td>{{ $ligne->date_inscription->format('d/m/Y') }}</td></tr>
        <tr><th>Total des actions B détenues</th><td>{{ format_nombre($totalDetenu) }}</td></tr>
    </table>

    <div class="encadre">
        <strong>L’achat d’actions ne donne aucun droit ni priorité pour obtenir une boutique.</strong><br>
        Le rendement n’est pas garanti.
    </div>
</x-document-pdf>
