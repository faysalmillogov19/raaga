<x-admin.carte-connexion :espace="$espace" :titre="__('admin.invitation.titre')" :intro="__('admin.invitation.intro', ['nom' => $invitation->user->name])">
    <form method="POST" action="{{ route($espace->route('invitation'), $jeton) }}" class="formulaire" x-data="{ envoi: false }" @submit="envoi = true">
        @csrf

        <div class="formulaire-champ">
            <label for="email">{{ __('admin.connexion.email') }}</label>
            <input id="email" type="email" value="{{ $invitation->user->email }}" class="champ bg-fond" readonly autocomplete="username">
        </div>

        <div class="formulaire-champ">
            <label for="password">{{ __('admin.connexion.mot_de_passe') }}</label>
            <input id="password" name="password" type="password" required autocomplete="new-password"
                   minlength="{{ config('raaga.backoffice.mot_de_passe_min') }}" aria-describedby="password-aide"
                   class="champ @error('password') est-invalide @enderror">
            <p id="password-aide" class="aide-champ">{{ __('admin.invitation.mot_de_passe_aide', ['n' => config('raaga.backoffice.mot_de_passe_min')]) }}</p>
            @error('password') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
        </div>

        <div class="formulaire-champ">
            <label for="password_confirmation">{{ __('admin.invitation.confirmation') }}</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" class="champ">
        </div>

        <button type="submit" class="btn-plein" :disabled="envoi">
            <span x-text="envoi ? @js(__('admin.connexion.patientez')) : @js(__('admin.invitation.continuer'))">{{ __('admin.invitation.continuer') }}</span>
        </button>
    </form>
</x-admin.carte-connexion>
