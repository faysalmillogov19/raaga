{{-- Armoiries : fichier déposé dans les paramètres de la plateforme (sinon /public/armoiries.svg). --}}
<img src="{{ \App\Support\Armoiries::url() }}" alt="{{ __('public.site.armoiries') }}" width="48" height="56"
     {{ $attributes->class(['h-14 w-auto' => ! $attributes->has('class')]) }}>
