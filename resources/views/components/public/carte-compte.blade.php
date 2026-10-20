@props(['titre', 'intro' => null])

{{-- Gabarit des pages Connexion / Inscription : carte centrée sur fond gris (sombre en thème « nuit »), aide sous la carte. --}}
<x-public.layout :titre="$titre">
    <div class="compte-fond">
        <div class="compte-carte">
            {{ $avant ?? '' }}

            @if (\App\Support\ThemePublic::sombre())
                {{-- Thème « nuit » : carte sombre, logo pour fond foncé (ou logo couleur sur sa plaque blanche). --}}
                <div class="mb-5 text-center"><x-logo variante="blanc" class="h-12" :alt="plateforme('identite.nomPlateforme')" /></div>
            @else
                <x-logo class="mx-auto mb-5 h-12" :alt="plateforme('identite.nomPlateforme')" />
            @endif
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
