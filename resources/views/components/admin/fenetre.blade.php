@props([
    'id', 'titre', 'action' => null, 'methode' => 'POST', 'bouton' => null, 'varianteBouton' => 'principal',
    'declencheurVariante' => 'principal', 'declencheurTaille' => 'normal', 'declencheurIcone' => null, 'declencheurLibelle' => null,
    'ouvrir' => false, 'fichiers' => false, 'ajax' => true, 'soumission' => false,
])

{{--
    Fenêtre modale du back-office (élément <dialog> natif ouvert par showModal()) : fond assombri, en-tête (titre et
    bouton « Fermer »), corps qui défile, pied « Annuler » + action. Fermeture par ×, Annuler, Échap ou clic sur le fond ;
    focus sur le premier champ à l'ouverture, rendu au bouton d'origine à la fermeture. Le formulaire est envoyé sans
    rechargement : erreurs de validation sous chaque champ (fenêtre laissée ouverte) ; en cas de succès, fenêtre fermée
    et contenu de la page mis à jour (ligne du tableau, message en haut de la liste).
    Slots : « declencheur » (texte du bouton d'ouverture, facultatif), contenu = champs ; « pied » remplace le pied par défaut.
--}}
@php
    $c = __('admin.composants.fenetre');
    $rouvrir = $ouvrir || (old('_fenetre') === $id && $errors->any());
@endphp
<div x-data="fenetre" {{ $attributes->merge(['class' => 'adm-fenetre-hote']) }} @if ($rouvrir) data-ouvrir @endif
     data-message-erreur="{{ $c['erreur'] }}" data-message-interdit="{{ $c['interdit'] }}" data-message-corriger="{{ $c['corriger'] }}">
    @isset($declencheur)
        <x-admin.bouton :variante="$declencheurVariante" :taille="$declencheurTaille" :icone="$declencheurIcone" :libelle="$declencheurLibelle"
                        id="{{ $id }}-ouvrir" x-on:click="ouvrir($event)" aria-haspopup="dialog" aria-controls="{{ $id }}">{{ $declencheur }}</x-admin.bouton>
    @endisset

    <dialog id="{{ $id }}" x-ref="dialogue" class="adm-fenetre" aria-labelledby="{{ $id }}-titre" aria-modal="true"
            @close="fermee()" @click="clicFond($event)">
        <form @if ($action) method="POST" action="{{ $action }}" @else method="dialog" @endif @if ($fichiers) enctype="multipart/form-data" @endif
              class="adm-fenetre-cadre" novalidate @if ($action && $ajax) @submit.prevent="envoyer($event)" @endif>
            @if ($action)
                @csrf
                @if (strtoupper($methode) !== 'POST') @method($methode) @endif
                <input type="hidden" name="_fenetre" value="{{ $id }}">
            @endif

            <header class="adm-fenetre-tete">
                <h2 id="{{ $id }}-titre" class="adm-fenetre-titre">{{ $titre }}</h2>
                <button type="button" class="adm-fenetre-fermer" x-on:click="fermer()" aria-label="{{ $c['fermer'] }}" title="{{ $c['fermer'] }}">
                    <x-icon name="fermer" class="h-5 w-5" />
                </button>
            </header>

            <div class="adm-fenetre-corps">
                <div x-ref="erreur" class="adm-message adm-message-erreur" role="alert" x-show="erreur" x-text="erreur" x-cloak></div>
                {{ $slot }}
            </div>

            <footer class="adm-fenetre-pied">
                @isset($pied)
                    {{ $pied }}
                @else
                    <x-admin.bouton variante="contour" x-on:click="fermer()">{{ $action ? $c['annuler'] : $c['fermer'] }}</x-admin.bouton>
                    @if ($action && $soumission)
                        {{-- Élément soumis à validation : « Enregistrer et soumettre » (principal), « Enregistrer en brouillon ». --}}
                        <x-admin.enregistrer-soumettre :erreurs="$rouvrir" x-bind:disabled="envoi" />
                    @elseif ($action)
                        <x-admin.bouton type="submit" :variante="$varianteBouton" x-bind:disabled="envoi" x-bind:aria-busy="envoi ? 'true' : 'false'">{{ $bouton ?? $c['enregistrer'] }}</x-admin.bouton>
                    @endif
                @endisset
            </footer>
        </form>
    </dialog>
</div>
