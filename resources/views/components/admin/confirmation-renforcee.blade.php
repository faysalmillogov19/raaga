@props(['id', 'titre', 'message', 'action', 'bouton', 'totp' => true])

{{-- Action critique : le mot « CONFIRMER » et un nouveau code TOTP sont exigés (vérifiés côté serveur). --}}
<div x-data="{ mot: '' }">
    <x-admin.bouton x-on:click="$refs.dialogue.showModal()" aria-haspopup="dialog">{{ $bouton }}</x-admin.bouton>

    <dialog x-ref="dialogue" class="bo-dialogue" aria-labelledby="{{ $id }}-titre">
        <form method="POST" action="{{ $action }}">
            @csrf
            <p class="surtitre">{{ __('admin.confirmation_renforcee.titre') }}</p>
            <h2 id="{{ $id }}-titre" class="!text-[1.2rem]">{{ $titre }}</h2>
            <p class="mt-2">{{ $message }}</p>

            <div class="formulaire-champ mt-4">
                <label for="{{ $id }}-mot"><span class="adm-requis" aria-hidden="true">*</span> {{ __('admin.confirmation_renforcee.mot') }}</label>
                <p class="aide-champ !mt-0">{{ __('admin.confirmation_renforcee.consigne', ['mot' => \App\Services\Backoffice\ConfirmationRenforcee::MOT]) }}</p>
                <input id="{{ $id }}-mot" name="confirmation" type="text" autocomplete="off" required x-model="mot" class="champ mt-1.5">
            </div>

            @if ($totp)
                <div class="formulaire-champ">
                    <label for="{{ $id }}-code"><span class="adm-requis" aria-hidden="true">*</span> {{ __('admin.confirmation_renforcee.code') }}</label>
                    <input id="{{ $id }}-code" name="code_totp" type="text" inputmode="numeric" autocomplete="one-time-code" required maxlength="6"
                           class="champ text-[1.2rem] font-bold tracking-[.3em]">
                </div>
            @endif

            <div class="mt-5 flex justify-end gap-3">
                <x-admin.bouton variante="contour" x-on:click="$refs.dialogue.close()">{{ __('admin.confirmation.annuler') }}</x-admin.bouton>
                <button type="submit" class="adm-btn adm-btn-principal adm-btn-normal" :disabled="mot !== @js(\App\Services\Backoffice\ConfirmationRenforcee::MOT)">{{ $bouton }}</button>
            </div>
        </form>
    </dialog>
</div>
