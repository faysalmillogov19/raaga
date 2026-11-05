{{--
    Récépissé de préinscription Raaga (réunion du 05/11/2026) : remis à une personne physique inscrite sur une boutique
    sans IFU. Numéro propre (jamais un numéro de commerçant), identité, boutique, site, date, délai de formalisation,
    QR code de vérification. En-tête commun : armoiries, logo, mentions légales (paramètres).
--}}
@php
    $r = $recepisse;
    $u = $r->user;
    $t = __('public.formalisation.recepisse');
@endphp

<x-document-pdf :titre="$t['titre'].' '.$r->numero" :pied="$t['titre'].' n° '.$r->numero.' · émis le '.$r->emis_le->format('d/m/Y')">
    <p class="surtitre">{{ $t['surtitre'] }}</p>
    <h1>{{ $t['titre'] }}</h1>
    <p>{{ $t['numero'] }} <strong>{{ $r->numero }}</strong>, {{ $t['emis_le'] }} {{ $r->emis_le->format('d/m/Y') }}</p>

    <table class="donnees">
        <tr><th>{{ $t['titulaire'] }}</th><td><strong>{{ trim(mb_strtoupper((string) $u->nom, 'UTF-8').' '.$u->prenoms) ?: $u->name }}</strong>@if ($u->date_naissance)<br>{{ $t['ne_le'] }} {{ $u->date_naissance->format('d/m/Y') }}@endif</td></tr>
        @if ($u->numero_piece)<tr><th>{{ $t['piece'] }}</th><td>{{ $u->numero_piece }}</td></tr>@endif
        <tr><th>{{ $t['boutique'] }}</th><td><strong>{{ $r->boutique?->code }}</strong>@if ($r->boutique?->type) ({{ $r->boutique->type->libelle }}@if ($r->boutique->surface), {{ format_nombre((float) $r->boutique->surface, 2) }} m²@endif)@endif</td></tr>
        <tr><th>{{ $t['site'] }}</th><td>{{ $r->boutique?->site?->nom }}</td></tr>
        <tr><th>{{ $t['date'] }}</th><td>{{ $r->emis_le->format('d/m/Y') }}</td></tr>
        <tr><th>{{ $t['echeance'] }}</th><td><strong>{{ $r->echeance_le->format('d/m/Y') }}</strong></td></tr>
    </table>

    <div class="encadre">
        <strong>{{ $t['mention_titre'] }}</strong><br>
        {{ __('public.formalisation.recepisse.mention', ['date' => $r->echeance_le->format('d/m/Y')]) }}
    </div>

    <table class="donnees">
        <tr>
            <td style="width: 34mm"><img src="{{ $qr }}" alt="{{ $t['qr'] }}" style="width: 30mm; height: 30mm"></td>
            <td>{{ $t['qr_aide'] }}</td>
        </tr>
    </table>
</x-document-pdf>
