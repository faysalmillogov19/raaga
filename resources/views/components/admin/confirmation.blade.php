@props([
    'id', 'titre', 'message', 'action', 'bouton' => null, 'motif' => false, 'danger' => false,
    'etape' => null, 'variante' => null, 'taille' => 'normal', 'methode' => 'POST', 'icone' => null, 'libelle' => null,
])

{{--
    Fenêtre de confirmation d'une action : titre, texte, motif (facultatif ou obligatoire), boutons Annuler / action.
    « etape » (soumettre, confirmer, rejeter, reviser) : bouton du circuit de validation, identique sur tous les écrans
    (Soumettre au validateur, Confirmer, Rejeter… avec motif obligatoire, Remettre en révision).
    Slots : « declencheur » (texte du bouton d'ouverture, sans « etape »), contenu = champs supplémentaires.
--}}
@php
    $c = __('admin.composants.circuit');
    $circuit = ['soumettre' => 'principal', 'confirmer' => 'principal', 'rejeter' => 'rejet', 'reviser' => 'discret'];
    if ($etape) {
        $variante ??= $circuit[$etape];
        $bouton = $c[$etape];
        $motif = $etape === 'rejeter' ? 'obligatoire' : $motif;
        $danger = $etape === 'rejeter' || $danger;
    }
    $variante ??= $danger ? 'rejet' : 'contour';
    $motif = $motif === true ? 'obligatoire' : $motif;
    $texteDeclencheur = $etape ? $c[$etape].($etape === 'rejeter' ? '…' : '') : ($declencheur ?? '');
@endphp
<div x-data class="adm-confirmation">
    <x-admin.bouton :variante="$variante" :taille="$taille" :icone="$icone" :libelle="$libelle" x-on:click="$refs.dialogue.showModal()" aria-haspopup="dialog">{{ $texteDeclencheur }}</x-admin.bouton>

    <dialog x-ref="dialogue" class="bo-dialogue adm-dialogue" aria-labelledby="{{ $id }}-titre" aria-describedby="{{ $id }}-texte">
        <form method="POST" action="{{ $action }}">
            @csrf
            @if (strtoupper($methode) !== 'POST') @method($methode) @endif
            <h2 id="{{ $id }}-titre" class="adm-dialogue-titre">{{ $titre }}</h2>
            <p id="{{ $id }}-texte" class="mt-2">{{ $message }}</p>

            {{ $slot }}

            @if ($motif)
                <div class="formulaire-champ adm-champ mt-4">
                    <label for="{{ $id }}-motif" class="adm-libelle">{{ __($motif === 'obligatoire' ? 'admin.composants.motif_obligatoire' : 'admin.composants.motif_facultatif') }}@if ($motif === 'obligatoire') <span class="adm-requis" aria-hidden="true">*</span>@endif</label>
                    <textarea id="{{ $id }}-motif" name="motif" rows="3" @if ($motif === 'obligatoire') required minlength="5" @endif maxlength="1000" class="champ"></textarea>
                </div>
            @endif

            <div class="mt-5 flex flex-wrap justify-end gap-3">
                <x-admin.bouton variante="contour" x-on:click="$refs.dialogue.close()">{{ __('admin.confirmation.annuler') }}</x-admin.bouton>
                <x-admin.bouton type="submit" :variante="$danger ? 'danger' : 'principal'">{{ $bouton }}</x-admin.bouton>
            </div>
        </form>
    </dialog>
</div>
