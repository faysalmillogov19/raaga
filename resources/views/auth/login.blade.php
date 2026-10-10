<x-guest-layout :titre="__('public.auth.connexion.titre')" :intro="__('public.auth.connexion.intro')">
    @if (session('status'))
        <x-public.alerte variante="info" class="mb-6" role="status">{{ session('status') }}</x-public.alerte>
    @endif

    <form method="POST" action="{{ route('login') }}" class="space-y-5" novalidate>
        @csrf

        <x-public.champ nom="email" type="email" :libelle="__('public.auth.email')"
                        required autofocus autocomplete="username" inputmode="email" />

        <x-public.champ nom="password" type="password" :libelle="__('public.auth.mot_de_passe')"
                        required autocomplete="current-password" />

        <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
            <x-public.case nom="remember" id="remember_me" :libelle="__('public.auth.connexion.se_souvenir')" />
            @if (Route::has('password.request'))
                <a href="{{ route('password.request') }}" class="lien inline-flex min-h-[44px] items-center">{{ __('public.auth.connexion.oubli') }}</a>
            @endif
        </div>

        <button type="submit" class="btn-primaire w-full">{{ __('public.auth.connexion.bouton') }}</button>
    </form>

    <div class="mt-6 border-t border-ligne pt-6 text-center">
        <p class="text-gris">{{ __('public.auth.connexion.pas_de_compte') }}</p>
        <a href="{{ route('register') }}" class="btn-secondaire mt-2 w-full">{{ __('public.auth.connexion.creer') }}</a>
    </div>

    <x-slot:apres>
        @if ($antiFraude = plateforme('messages.messageAntiFraude'))
            <x-public.alerte variante="attention" class="mt-6">{{ $antiFraude }}</x-public.alerte>
        @endif
    </x-slot:apres>
</x-guest-layout>
