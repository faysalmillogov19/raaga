<x-guest-layout :titre="__('public.auth.reinitialisation.titre')" :intro="__('public.auth.reinitialisation.intro')">
    <form method="POST" action="{{ route('password.store') }}" class="space-y-5" novalidate>
        @csrf

        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <x-public.champ nom="email" type="email" :libelle="__('public.auth.email')" :valeur="$request->email"
                        required autofocus autocomplete="username" inputmode="email" />

        <x-public.champ nom="password" type="password" :libelle="__('public.auth.nouveau_mot_de_passe')"
                        :aide="__('public.auth.aide_mot_de_passe')" required autocomplete="new-password" />

        <x-public.champ nom="password_confirmation" type="password" :libelle="__('public.auth.confirmation')"
                        required autocomplete="new-password" />

        <button type="submit" class="btn-primaire w-full">{{ __('public.auth.reinitialisation.bouton') }}</button>
    </form>
</x-guest-layout>
