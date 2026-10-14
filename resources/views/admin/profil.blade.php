<x-admin.layout :titre="__('admin.profil.titre')" :fil="[['libelle' => __('admin.profil.titre')]]">
    @php($min = config('raaga.backoffice.mot_de_passe_min'))

    <div class="grid gap-[18px] xl:grid-cols-2">
        {{-- Informations --}}
        <section class="bo-carte !mt-0" aria-labelledby="infos-titre">
            <h2 id="infos-titre" class="!text-[1.15rem]">{{ __('admin.profil.informations') }}</h2>
            <p class="texte-carte mt-1">{{ __('admin.profil.informations_intro') }}</p>

            <form method="POST" action="{{ route('admin.profil.update') }}" class="formulaire">
                @csrf
                <div class="formulaire-champ">
                    <label for="name">{{ __('admin.profil.nom') }}</label>
                    <input id="name" name="name" type="text" required maxlength="150" autocomplete="name"
                           value="{{ old('name', $user->name) }}" class="champ @error('name', 'informations') est-invalide @enderror">
                    @error('name', 'informations') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
                </div>
                <div class="formulaire-champ">
                    <label for="email">{{ __('admin.profil.email') }}</label>
                    <input id="email" name="email" type="email" required autocomplete="username"
                           value="{{ old('email', $user->email) }}" class="champ @error('email', 'informations') est-invalide @enderror">
                    @error('email', 'informations') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
                </div>
                <div class="formulaire-champ">
                    <span class="formulaire-libelle">{{ __('admin.profil.role') }}</span>
                    <p>{{ $user->codeRole()->badge() }} <span class="texte-carte block">{{ __('admin.profil.role_aide') }}</span></p>
                </div>
                <div class="formulaire-champ">
                    <label for="mdp-infos">{{ __('admin.profil.mot_de_passe_actuel') }}</label>
                    <input id="mdp-infos" name="mot_de_passe_actuel" type="password" required autocomplete="current-password"
                           class="champ @error('mot_de_passe_actuel', 'informations') est-invalide @enderror">
                    @error('mot_de_passe_actuel', 'informations') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
                </div>
                <button type="submit" class="bo-bouton bo-bouton-principal mt-5">{{ __('admin.profil.enregistrer') }}</button>
            </form>
        </section>

        <div class="space-y-[18px]">
            {{-- Mot de passe --}}
            <section class="bo-carte !mt-0" aria-labelledby="mdp-titre">
                <h2 id="mdp-titre" class="!text-[1.15rem]">{{ __('admin.profil.mot_de_passe') }}</h2>
                <form method="POST" action="{{ route('admin.profil.mot-de-passe') }}" class="formulaire">
                    @csrf
                    <div class="formulaire-champ">
                        <label for="mdp-actuel">{{ __('admin.profil.mot_de_passe_actuel') }}</label>
                        <input id="mdp-actuel" name="mot_de_passe_actuel" type="password" required autocomplete="current-password"
                               class="champ @error('mot_de_passe_actuel', 'motDePasse') est-invalide @enderror">
                        @error('mot_de_passe_actuel', 'motDePasse') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
                    </div>
                    <div class="formulaire-champ">
                        <label for="mdp-nouveau">{{ __('admin.profil.nouveau_mot_de_passe') }}</label>
                        <input id="mdp-nouveau" name="password" type="password" required minlength="{{ $min }}" autocomplete="new-password"
                               aria-describedby="mdp-aide" class="champ @error('password', 'motDePasse') est-invalide @enderror">
                        <p id="mdp-aide" class="aide-champ">{{ __('admin.profil.mot_de_passe_aide', ['n' => $min]) }}</p>
                        @error('password', 'motDePasse') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
                    </div>
                    <div class="formulaire-champ">
                        <label for="mdp-confirmation">{{ __('admin.profil.confirmation') }}</label>
                        <input id="mdp-confirmation" name="password_confirmation" type="password" required autocomplete="new-password" class="champ">
                    </div>
                    <button type="submit" class="bo-bouton bo-bouton-principal mt-5">{{ __('admin.profil.changer') }}</button>
                </form>
            </section>

            {{-- Double authentification --}}
            <section class="bo-carte" aria-labelledby="securite-titre">
                <h2 id="securite-titre" class="!text-[1.15rem]">{{ __('admin.profil.securite') }}</h2>
                <p class="mt-2">{{ $user->totpActive() ? __('admin.profil.securite_active', ['date' => $user->totp_active_le->format('d/m/Y')]) : __('admin.profil.securite_inactive') }}</p>
            </section>

            {{-- Préférences : thème du back-office, mémorisé pour le compte. --}}
            <section class="bo-carte" id="preferences" aria-labelledby="preferences-titre">
                <h2 id="preferences-titre" class="!text-[1.15rem]">{{ __('admin.cadre.preferences_titre') }}</h2>
                <p class="texte-carte mt-1">{{ __('admin.cadre.preferences_aide') }}</p>
                <form method="POST" action="{{ route('admin.preferences.theme') }}" class="mt-3">
                    @csrf
                    <fieldset class="flex flex-wrap gap-4">
                        <legend class="sr-only">{{ __('admin.cadre.theme') }}</legend>
                        @foreach (\App\Http\Controllers\Admin\CadreController::THEMES as $choix)
                            <label class="flex min-h-[44px] items-center gap-2">
                                <input type="radio" name="theme" value="{{ $choix }}" @checked(($user->theme_backoffice ?? 'clair') === $choix)>
                                {{ __('admin.cadre.theme_'.$choix) }}
                            </label>
                        @endforeach
                    </fieldset>
                    <button type="submit" class="bo-bouton bo-bouton-principal mt-3">{{ __('admin.cadre.enregistrer') }}</button>
                </form>
            </section>
        </div>
    </div>
</x-admin.layout>
