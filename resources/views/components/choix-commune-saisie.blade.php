@props(['id'])

{{-- Champ de saisie et suggestions de la commune, dans un élément x-data="choixCommune" (voir x-choix-commune). --}}
@php($t = __('public.compte.inscription'))
<input type="text" id="{{ $id }}-saisie" x-ref="saisie" hidden class="champ" autocomplete="off" spellcheck="false"
       placeholder="{{ $t['commune_saisie'] }}" role="combobox" aria-autocomplete="list" aria-expanded="false"
       aria-controls="{{ $id }}-suggestions" :aria-expanded="ouvert ? 'true' : 'false'"
       :aria-activedescendant="ouvert && actif >= 0 ? idOption(actif) : null"
       x-model="saisie" @input="saisir()" @focus="ouvrir()" @click="ouvrir()" @blur="quitter()"
       @keydown.down.prevent="deplacer(1)" @keydown.up.prevent="deplacer(-1)" @keydown.enter="valider($event)"
       @keydown.escape="fermer()">
<ul id="{{ $id }}-suggestions" role="listbox" class="choix-commune-liste" x-show="ouvert" x-cloak>
    <template x-for="(c, i) in resultats" :key="c.valeur">
        <li :id="idOption(i)" role="option" class="choix-commune-option" :class="{ 'est-actif': i === actif }"
            :aria-selected="i === actif ? 'true' : 'false'" @mousedown.prevent="choisir(c)" @mousemove="actif = i">
            <span class="choix-commune-nom" x-text="c.nom"></span>
            <span class="choix-commune-province" x-text="c.province"></span>
        </li>
    </template>
    <li class="choix-commune-vide" role="presentation" x-show="resultats.length === 0">{{ $t['commune_aucune'] }}</li>
</ul>
