@props(['id'])

{{--
    Commune de résidence (06/11/2026) : la liste des communes (dans le contenu) reçoit un champ de saisie avec
    suggestions (public/js/app.js, choixCommune). La liste reste la valeur envoyée ; sans JavaScript, elle s'affiche seule.
--}}
<div class="choix-commune" x-data="choixCommune" @click.outside="fermer()" data-choix-commune>
    <x-choix-commune-saisie :id="$id" />
    {{ $slot }}
</div>
