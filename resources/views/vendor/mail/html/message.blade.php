<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="url('/')">
{{ plateforme('identite.nomPlateforme') }}
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} {{ plateforme('mentions.denominationSociale') ?: plateforme('identite.nomPlateforme') }}. {{ __('All rights reserved.') }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
