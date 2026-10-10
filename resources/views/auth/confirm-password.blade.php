<x-guest-layout :titre="__('public.auth.confirmation_mdp.titre')" :intro="__('public.auth.confirmation_mdp.intro')">
    <form method="POST" action="{{ route('password.confirm') }}" class="space-y-5" novalidate>
        @csrf

        <x-public.champ nom="password" type="password" :libelle="__('public.auth.mot_de_passe')"
                        required autofocus autocomplete="current-password" />

        <button type="submit" class="btn-primaire w-full">{{ __('public.auth.confirmation_mdp.bouton') }}</button>
    </form>
</x-guest-layout>
