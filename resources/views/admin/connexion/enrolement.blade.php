<x-admin.carte-connexion :espace="$espace" :titre="__('admin.connexion.enrolement_titre')" :intro="__('admin.connexion.enrolement_intro')">
    <ol class="formulaire list-decimal space-y-4 pl-5">
        <li>
            <p>{{ __('admin.connexion.enrolement_etape1') }}</p>
            <div class="mt-3 inline-block rounded-charte border border-ligne bg-white p-2" role="img" aria-label="QR code">{!! $qrCode !!}</div>
            <p class="aide-champ">{{ __('admin.connexion.enrolement_cle') }}</p>
            <p class="font-mono text-[.95rem] font-bold tracking-wider text-encre">{{ $secret }}</p>
        </li>
        <li>{{ __('admin.connexion.enrolement_etape2') }}</li>
    </ol>

    <form method="POST" action="{{ route($espace->route('connexion.enrolement')) }}" class="formulaire" x-data="{ envoi: false }" @submit="envoi = true">
        @csrf
        <div class="formulaire-champ">
            <label for="code"><span class="champ-requis" aria-hidden="true">*</span> {{ __('admin.connexion.code') }}</label>
            <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" required maxlength="6"
                   class="champ text-[1.3rem] font-bold tracking-[.3em] @error('code') est-invalide @enderror">
            @error('code')
                <p class="message-erreur" role="alert">{{ $message }}</p>
            @enderror
        </div>
        <button type="submit" class="btn-plein" :disabled="envoi">
            <span x-text="envoi ? @js(__('admin.connexion.patientez')) : @js(__('admin.connexion.activer'))">{{ __('admin.connexion.activer') }}</span>
        </button>
    </form>
</x-admin.carte-connexion>
