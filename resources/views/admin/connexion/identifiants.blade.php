<x-admin.carte-connexion :espace="$espace" :titre="__($espace->textes().'.titre')" :intro="__($espace->textes().'.intro')">
    <form method="POST" action="{{ route($espace->route('connexion')) }}" class="formulaire" x-data="{ envoi: false }" @submit="envoi = true">
        @csrf

        <div class="formulaire-champ">
            <label for="email"><span class="champ-requis" aria-hidden="true">*</span> {{ __('admin.connexion.email') }}</label>
            <input id="email" name="email" type="email" autocomplete="username" required autofocus
                   value="{{ old('email') }}" class="champ @error('email') est-invalide @enderror"
                   @error('email') aria-invalid="true" aria-describedby="email-erreur" @enderror>
            @error('email')
                <p id="email-erreur" class="message-erreur" role="alert">{{ $message }}</p>
            @enderror
        </div>

        <div class="formulaire-champ">
            <label for="password"><span class="champ-requis" aria-hidden="true">*</span> {{ __('admin.connexion.mot_de_passe') }}</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required
                   class="champ @error('password') est-invalide @enderror">
            @error('password')
                <p class="message-erreur" role="alert">{{ $message }}</p>
            @enderror
        </div>

        <button type="submit" class="btn-plein" :disabled="envoi">
            <span x-text="envoi ? @js(__('admin.connexion.patientez')) : @js(__('admin.connexion.continuer'))">{{ __('admin.connexion.continuer') }}</span>
        </button>
    </form>
</x-admin.carte-connexion>
