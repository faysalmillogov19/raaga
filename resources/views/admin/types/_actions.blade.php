{{-- Boutons d'action d'une ligne de la liste des types de boutiques. --}}
<div class="flex gap-2">
    <x-admin.bouton-icone :href="route('admin.sites.types.show', $type)" icone="voir" :intitule="__('admin.types.voir').' '.$type->libelle" :infobulle="__('admin.types.voir')" />
</div>
