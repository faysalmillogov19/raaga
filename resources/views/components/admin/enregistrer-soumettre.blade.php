{{--
    Boutons d'un formulaire d'ajout ou de modification soumis à validation : « Enregistrer et soumettre » (principal)
    et « Enregistrer en brouillon » (secondaire). Si le contrôle de soumission échoue, l'élément reste en brouillon et
    la raison s'affiche ici (ou sous le champ concerné).
--}}
@props(['erreurs' => true])
@php($c = __('admin.composants.circuit'))
@if ($erreurs)
@error('soumission')
    <p class="message-erreur w-full" role="alert" data-erreur-soumission>{{ $message }}</p>
@enderror
@endif
<x-admin.bouton type="submit" variante="principal" name="apres" value="soumettre" {{ $attributes }}>{{ $c['enregistrer_soumettre'] }}</x-admin.bouton>
<x-admin.bouton type="submit" variante="secondaire" name="apres" value="brouillon" {{ $attributes }}>{{ $c['enregistrer_brouillon'] }}</x-admin.bouton>
