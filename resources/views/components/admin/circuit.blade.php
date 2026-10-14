@props(['etape' => 'brouillon'])

{{-- « Enregistrer le brouillon » : bouton d'envoi du formulaire de saisie, en contour, identique sur tous les écrans. --}}
<x-admin.bouton type="submit" variante="contour" {{ $attributes }}>{{ __('admin.composants.circuit.'.$etape) }}</x-admin.bouton>
