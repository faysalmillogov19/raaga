@props(['titre', 'intro' => null])

{{-- Gabarit des pages Connexion / Inscription : carte centrée sur fond gris, aide sous la carte. --}}
<x-public.layout :titre="$titre">
    <div class="compte-fond">
        <div class="compte-carte">
            {{ $avant ?? '' }}

            <x-public.armoiries class="compte-armoiries" />
            <h1>{{ $titre }}</h1>
            @if ($intro)
                <p class="compte-intro">{{ $intro }}</p>
            @endif

            {{ $slot }}

            @isset($pied)
                <p class="compte-pied">{{ $pied }}</p>
            @endisset
        </div>

        <p class="compte-aide">
            {{ __('public.compte.besoin_aide') }}
            @if ($numeroVert = plateforme('contacts.numeroVert'))
                {{ __('public.compte.numero_vert') }} <strong>{{ \App\Support\Telephone::formaterLocal($numeroVert) }}</strong> ·
            @endif
            <a href="{{ route('faq') }}">{{ __('public.compte.aide_faq') }}</a>
        </p>
        @if ($antiFraude = plateforme('messages.messageAntiFraude'))
            <p class="compte-aide !mt-2">{{ $antiFraude }}</p>
        @endif
    </div>
</x-public.layout>
