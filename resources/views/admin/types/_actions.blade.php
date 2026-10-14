{{-- Actions d'une ligne de la liste des types de boutiques. --}}
<div class="flex justify-end gap-2">
    <x-admin.bouton variante="contour" taille="petit" :href="route('admin.sites.types.show', $type)" aria-label="{{ __('admin.types.voir') }} {{ $type->libelle }}">{{ __('admin.sites.liste.voir_court') }}</x-admin.bouton>
</div>
