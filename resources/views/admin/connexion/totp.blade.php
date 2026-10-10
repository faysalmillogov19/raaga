<x-admin.carte-connexion :espace="$espace" :titre="__('admin.connexion.totp_titre')" :intro="__('admin.connexion.totp_intro')">
    <form method="POST" action="{{ route($espace->route('connexion.totp')) }}" class="formulaire" x-data="{ envoi: false }" @submit="envoi = true">
        @csrf

        <div class="formulaire-champ">
            <label for="code">{{ __('admin.connexion.code') }}</label>
            <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" required autofocus
                   maxlength="20" class="champ text-[1.3rem] font-bold tracking-[.3em] @error('code') est-invalide @enderror"
                   aria-describedby="code-aide @error('code') code-erreur @enderror">
            <p id="code-aide" class="aide-champ">{{ __('admin.connexion.code_aide') }}</p>
            @error('code')
                <p id="code-erreur" class="message-erreur" role="alert">{{ $message }}</p>
            @enderror
        </div>

        <button type="submit" class="btn-plein" :disabled="envoi">
            <span x-text="envoi ? @js(__('admin.connexion.patientez')) : @js(__('admin.connexion.valider'))">{{ __('admin.connexion.valider') }}</span>
        </button>
    </form>

    <p class="compte-pied"><a href="{{ route($espace->route('connexion')) }}">{{ __('admin.connexion.retour') }}</a></p>
</x-admin.carte-connexion>
