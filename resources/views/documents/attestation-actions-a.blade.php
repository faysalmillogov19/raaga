{{-- Attestation d'actionnaire (actions A), générée à chaque inscription au registre A. --}}
<x-document-pdf :titre="'Attestation d’actionnaire · '.$numero_certificat"
                :pied="'Certificat '.$numero_certificat.' · registre des actions A · émis le '.\Carbon\Carbon::parse($date_inscription)->format('d/m/Y')">
    <p class="surtitre">Registre des actions A</p>
    <h1>Attestation d’actionnaire</h1>

    <p>La société atteste que l’institution ci-dessous est inscrite au registre des actions A pour les actions suivantes :</p>

    <table class="donnees">
        <tr><th>Institution</th><td><strong>{{ $institution->denomination }}</strong>@if ($institution->sigle) ({{ $institution->sigle }})@endif</td></tr>
        @if ($institution->rccm)<tr><th>RCCM</th><td>{{ $institution->rccm }}</td></tr>@endif
        @if ($institution->ifu)<tr><th>IFU</th><td>{{ $institution->ifu }}</td></tr>@endif
        <tr><th>Convention de souscription</th><td>{{ $convention->reference }}</td></tr>
        <tr><th>Numéro de certificat</th><td>{{ $numero_certificat }}</td></tr>
        <tr><th>Actions inscrites</th><td><strong>{{ format_nombre($nb_actions) }} actions A</strong></td></tr>
        <tr><th>Numéros des actions</th><td>{{ format_nombre($numero_debut) }} à {{ format_nombre($numero_fin) }}</td></tr>
        <tr><th>Date d’inscription</th><td>{{ \Carbon\Carbon::parse($date_inscription)->translatedFormat('j F Y') }}</td></tr>
        <tr><th>Total des actions A détenues</th><td>{{ format_nombre($totalDetenu) }} sur {{ format_nombre($convention->nb_actions) }} prévues par la convention</td></tr>
    </table>

    <div class="encadre">Cette inscription est scellée dans le registre des actions A : chaque ligne contient l’empreinte numérique de la précédente, ce qui rend toute modification ultérieure détectable.</div>
</x-document-pdf>
