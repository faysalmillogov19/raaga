{{--
    En-tête des documents officiels (règlement, avis, reçus, attestations, rapports) : identité et
    mentions légales issues des paramètres de la plateforme. Un champ vide n'est pas affiché.
--}}
@php
    $m = plateforme('mentions');
    $siege = plateforme('siege');
    $numeroVert = plateforme('contacts.numeroVert');
    $identification = array_filter([
        $m['formeJuridique'],
        $m['capitalSocial'] !== null ? 'capital de '.format_fcfa($m['capitalSocial']) : null,
        $m['rccm'] ? 'RCCM '.$m['rccm'] : null,
        $m['ifu'] ? 'IFU '.$m['ifu'] : null,
    ]);
    $coordonnees = array_filter([
        $siege['adresseSiege'] ? str_replace("\n", ', ', $siege['adresseSiege']) : null,
        $siege['boitePostale'],
        $numeroVert ? 'Numéro vert '.\App\Support\Telephone::formaterLocal($numeroVert) : null,
        plateforme('identite.urlSiteOfficiel'),
    ]);
@endphp

@if ($pdf ?? false)
    {{-- Version PDF (dompdf) : styles en ligne, armoiries intégrées. --}}
    <table style="width:100%; border-bottom:1px solid #D9E0DB; padding-bottom:8px; margin-bottom:16px;">
        <tr>
            <td style="width:60px; vertical-align:top;"><img src="{{ \App\Support\Armoiries::dataUri() }}" alt="" style="height:52px;"></td>
            <td style="font-size:10pt; line-height:1.35;">
                <strong style="font-size:11.5pt;">{{ $m['denominationSociale'] ?: plateforme('identite.nomPlateforme') }}</strong><br>
                @if ($identification){{ implode(' · ', $identification) }}<br>@endif
                @if ($coordonnees){{ implode(' · ', $coordonnees) }}@endif
            </td>
            @if ($logo = \App\Support\ImagesPlateforme::dataUri('logoHorizontal'))
                <td style="width:150px; text-align:right; vertical-align:top;"><img src="{{ $logo }}" alt="" style="height:40px;"></td>
            @endif
        </tr>
    </table>
@else
    <div class="document-entete flex items-start gap-4 border-b border-ligne pb-4">
        <img src="{{ \App\Support\Armoiries::url() }}" alt="" class="h-14 w-auto">
        <div class="text-[0.9rem] leading-snug">
            <p class="font-extrabold text-encre">{{ $m['denominationSociale'] ?: plateforme('identite.nomPlateforme') }}</p>
            @if ($identification)<p class="texte-carte">{{ implode(' · ', $identification) }}</p>@endif
            @if ($coordonnees)<p class="texte-carte">{{ implode(' · ', $coordonnees) }}</p>@endif
        </div>
        <x-logo class="ml-auto h-10 shrink-0" alt="" />
    </div>
@endif
