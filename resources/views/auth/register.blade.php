<x-guest-layout :titre="__('public.auth.inscription.titre')" :intro="__('public.auth.inscription.intro')">
    <form method="POST" action="{{ route('register') }}" class="space-y-5" novalidate>
        @csrf

        <x-public.champ nom="name" :libelle="__('public.auth.inscription.nom')" required autofocus autocomplete="name" />

        <x-public.champ nom="email" type="email" :libelle="__('public.auth.email')"
                        required autocomplete="username" inputmode="email" />

        <x-public.liste nom="role" :libelle="__('public.auth.inscription.profil')" required
                        :valeur="\App\Enums\Role::Citoyen->value"
                        :options="collect($roles)->mapWithKeys(fn ($role) => [$role->value => $role->label()])->all()" />

        <x-public.champ nom="password" type="password" :libelle="__('public.auth.mot_de_passe')"
                        :aide="__('public.auth.aide_mot_de_passe')" required autocomplete="new-password" />

        <x-public.champ nom="password_confirmation" type="password" :libelle="__('public.auth.confirmation')"
                        required autocomplete="new-password" />

        <button type="submit" class="btn-primaire w-full">{{ __('public.auth.inscription.bouton') }}</button>
    </form>

    <p class="mt-6 border-t border-ligne pt-6 text-center text-gris">
        {{ __('public.auth.inscription.deja') }}
        <a href="{{ route('login') }}" class="lien inline-flex min-h-[44px] items-center">{{ __('public.auth.inscription.connexion') }}</a>
    </p>
</x-guest-layout>
