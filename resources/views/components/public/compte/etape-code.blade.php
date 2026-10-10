@props(['action', 'renvoyer', 'telephone', 'secondes' => 0, 'bouton'])

{{-- Étape « code reçu par SMS » (connexion et inscription). Le slot reçoit le lien de modification ou de retour. --}}
<div x-data="codeOtp(@js(['secondes' => $secondes, 'erreur' => $errors->first('code'), 'message' => __('public.compte.erreurs.code')]))">
    {{-- Le numéro ne se coupe jamais en fin de ligne. --}}
    <p class="formulaire">{!! __('public.compte.code_envoye', ['telephone' => '<strong class="whitespace-nowrap">'.e(\App\Support\Telephone::formater($telephone)).'</strong>']) !!}</p>
    {{ $slot }}

    <form method="POST" action="{{ $action }}" class="formulaire" novalidate x-ref="formulaire" @submit="valider($event)">
        @csrf
        <fieldset>
            <legend class="formulaire-libelle">{{ __('public.compte.code') }}</legend>
            <div class="otp-grille" @paste="coller($event)">
                @for ($i = 0; $i < 6; $i++)
                    <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6"
                           class="otp @error('code') est-invalide @enderror" :class="{ 'est-invalide': erreur }"
                           aria-label="{{ __('public.compte.chiffre', ['n' => $i + 1]) }}"
                           @if ($i === 0) autocomplete="one-time-code" @else autocomplete="off" @endif
                           aria-describedby="code-erreur"
                           @input="saisir({{ $i }}, $event)" @keydown="touche({{ $i }}, $event)" @focus="$event.target.select()">
                @endfor
            </div>
        </fieldset>
        <input type="hidden" name="code" :value="code">
        <p id="code-erreur" class="message-erreur" x-show="erreur" x-text="erreur" role="alert"
           @if (! $errors->has('code')) x-cloak @endif>{{ $errors->first('code') }}</p>

        <button type="submit" class="btn-plein" :disabled="envoi">
            <span x-text="envoi ? @js(__('public.compte.patientez')) : @js($bouton)">{{ $bouton }}</span>
        </button>
    </form>

    <form method="POST" action="{{ $renvoyer }}">
        @csrf
        <button type="submit" class="lien-action" :disabled="restant > 0" @if ($secondes > 0) disabled @endif>
            {{ __('public.compte.renvoyer') }}&nbsp;<span x-show="restant > 0" x-text="minuteurTexte"></span>
        </button>
    </form>
</div>
