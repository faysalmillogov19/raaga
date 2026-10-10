@props(['url'])
{{-- En-tête des e-mails : logo Raaga en PNG 2x (les messageries affichent mal le SVG). --}}
@php
    $logo = \App\Support\ImagesPlateforme::type('logoHorizontal') === 'image/png'
        ? \App\Support\ImagesPlateforme::url('logoHorizontal')
        : asset('images/logo/raaga-logo-horizontal-email.png');
@endphp
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
<img src="{{ $logo }}" alt="{{ trim(strip_tags($slot)) }}" height="48" style="height: 48px; width: auto; max-width: 100%;">
</a>
</td>
</tr>
