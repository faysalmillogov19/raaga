@props(['action', 'valeur' => '', 'focus' => true])

{{-- Étape « numéro de téléphone » (connexion et inscription). Le slot reçoit le message d'erreur enrichi éventuel. --}}
<form method="POST" action="{{ $action }}" class="formulaire" novalidate
      x-data="saisieTelephone(@js(['valeur' => old('telephone', $valeur), 'erreur' => $errors->first('telephone'), 'message' => __('public.compte.erreurs.telephone')]))"
      @submit="valider($event)">
    @csrf

    <div class="formulaire-champ">
        <label for="telephone">{{ __('public.compte.telephone') }}</label>
        <div class="prefixe">
            <span aria-hidden="true">+226</span>
            <input id="telephone" name="telephone" type="tel" inputmode="tel" autocomplete="tel-national"
                   class="champ @error('telephone') est-invalide @enderror" :class="{ 'est-invalide': erreur }"
                   placeholder="70 12 34 56" maxlength="11" required @if ($focus) autofocus @endif
                   aria-describedby="telephone-aide telephone-erreur"
                   :aria-invalid="erreur ? 'true' : 'false'"
                   value="{{ old('telephone', $valeur) }}" :value="valeur" x-ref="telephone" @input="saisir($event)">
        </div>
        <p id="telephone-aide" class="aide-champ">{{ __('public.compte.telephone_aide') }}</p>
        <p id="telephone-erreur" class="message-erreur" x-show="erreur" x-text="erreur" role="alert"
           @if (! $errors->has('telephone')) x-cloak @endif>{{ $errors->first('telephone') }}</p>
    </div>

    {{ $slot }}

    <button type="submit" class="btn-plein" :disabled="envoi">
        <span x-text="envoi ? @js(__('public.compte.patientez')) : @js(__('public.compte.recevoir_code'))">{{ __('public.compte.recevoir_code') }}</span>
    </button>
</form>
