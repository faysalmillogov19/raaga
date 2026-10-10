@props(['id', 'titre', 'message', 'action', 'bouton', 'motif' => false, 'danger' => false])

{{--
    Boîte de confirmation d'une action irréversible : rappelle ses conséquences avant l'envoi.
    Slots : « declencheur » (texte du bouton qui ouvre la boîte), contenu = champs supplémentaires.
--}}
<div x-data>
    <button type="button" @class(['bo-bouton', 'bo-bouton-danger' => $danger, 'bo-bouton-secondaire' => ! $danger])
            @click="$refs.dialogue.showModal()" aria-haspopup="dialog">
        {{ $declencheur }}
    </button>

    <dialog x-ref="dialogue" class="bo-dialogue" aria-labelledby="{{ $id }}-titre">
        <form method="POST" action="{{ $action }}">
            @csrf
            <h2 id="{{ $id }}-titre" class="!text-[1.2rem]">{{ $titre }}</h2>
            <p class="mt-2">{{ $message }}</p>

            {{ $slot }}

            @if ($motif)
                <div class="formulaire-champ mt-4">
                    <label for="{{ $id }}-motif">{{ __('admin.utilisateurs.actions.motif') }}</label>
                    <textarea id="{{ $id }}-motif" name="motif" rows="3" required minlength="5" maxlength="500" class="champ"></textarea>
                </div>
            @endif

            <div class="mt-5 flex justify-end gap-3">
                <button type="button" class="bo-bouton bo-bouton-secondaire" @click="$refs.dialogue.close()">{{ __('admin.confirmation.annuler') }}</button>
                <button type="submit" @class(['bo-bouton', 'bo-bouton-danger' => $danger, 'bo-bouton-principal' => ! $danger])>{{ $bouton }}</button>
            </div>
        </form>
    </dialog>
</div>
