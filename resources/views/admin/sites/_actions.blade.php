{{-- Boutons d'action d'une ligne de la liste des sites. --}}
@php
    $l = __('admin.sites.liste');
    $gerer = auth()->user()->can(\App\Enums\Droit::SitesGerer->value);
@endphp
<div class="flex gap-2">
    <x-admin.bouton-icone :href="route('admin.sites.show', $site)" icone="voir" :intitule="$l['voir'].' '.$site->nom" :infobulle="$l['voir']" />
    <x-admin.bouton-icone :href="route('admin.referencement.capacites', ['site' => $site->id])" icone="simulations" :intitule="$l['capacites'].' '.$site->nom" :infobulle="$l['capacites']" />
    @if ($gerer)
        @if ($site->estModifiable())
            <x-admin.bouton-icone :href="route('admin.sites.edit', $site)" icone="modifier" :intitule="$l['modifier'].' '.$site->nom" :infobulle="$l['modifier']" />
        @else
            <x-admin.bouton-icone icone="modifier" :intitule="$l['modifier'].' '.$site->nom" :infobulle="$l['non_modifiable']" disabled />
        @endif
    @endif
</div>
