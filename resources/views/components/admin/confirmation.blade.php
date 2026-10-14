@props([
    'id', 'titre', 'message', 'action', 'bouton' => null, 'motif' => false, 'danger' => false,
    'etape' => null, 'variante' => null, 'taille' => 'normal', 'methode' => 'POST', 'icone' => null, 'libelle' => null,
])

{{--
    Confirmation d'une action dans une fenêtre modale (<x-admin.fenetre>) : titre, texte, motif (facultatif ou
    obligatoire), Annuler / action. Utilisée pour rejeter, supprimer, désactiver…
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
<x-admin.fenetre :id="$id" :titre="$titre" :action="$action" :methode="$methode" :bouton="$bouton" :variante-bouton="$danger ? 'danger' : 'principal'"
                 :declencheur-variante="$variante" :declencheur-taille="$taille" :declencheur-icone="$icone" :declencheur-libelle="$libelle">
    <x-slot:declencheur>{{ $texteDeclencheur }}</x-slot:declencheur>

    <p id="{{ $id }}-texte">{{ $message }}</p>

    {{ $slot }}

    @if ($motif)
        <div class="formulaire-champ adm-champ mt-4">
            <label for="{{ $id }}-motif" class="adm-libelle">{{ __($motif === 'obligatoire' ? 'admin.composants.motif_obligatoire' : 'admin.composants.motif_facultatif') }}@if ($motif === 'obligatoire') <span class="adm-requis" aria-hidden="true">*</span>@endif</label>
            <textarea id="{{ $id }}-motif" name="motif" rows="3" @if ($motif === 'obligatoire') required minlength="5" @endif maxlength="1000" class="champ"></textarea>
        </div>
    @endif
</x-admin.fenetre>
