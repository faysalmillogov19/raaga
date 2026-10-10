<x-guest-layout :titre="__('public.auth.oubli.titre')" :intro="__('public.auth.oubli.intro')">
    @if (session('status'))
        <x-public.alerte variante="info" class="mb-6" role="status">{{ session('status') }}</x-public.alerte>
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="space-y-5" novalidate>
        @csrf

        <x-public.champ nom="email" type="email" :libelle="__('public.auth.email')"
                        required autofocus autocomplete="username" inputmode="email" />

        <button type="submit" class="btn-primaire w-full">{{ __('public.auth.oubli.bouton') }}</button>
    </form>

    <p class="mt-6 border-t border-ligne pt-6 text-center">
        <a href="{{ route('login') }}" class="lien inline-flex min-h-[44px] items-center">← {{ __('public.auth.retour_connexion') }}</a>
    </p>
</x-guest-layout>
