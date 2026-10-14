{{-- Actions d'une ligne de la liste des sites. --}}
@php
    $l = __('admin.sites.liste');
    $gerer = auth()->user()->can(\App\Enums\Droit::SitesGerer->value);
@endphp
<div class="flex justify-end gap-2">
    <x-admin.bouton variante="contour" taille="petit" :href="route('admin.sites.show', $site)" aria-label="{{ $l['voir'] }} {{ $site->nom }}">{{ $l['voir_court'] }}</x-admin.bouton>
    <x-admin.bouton variante="contour" taille="petit" :href="route('admin.referencement.capacites', ['site' => $site->id])" aria-label="{{ $l['capacites'] }} {{ $site->nom }}">{{ $l['capacites_court'] }}</x-admin.bouton>
    @if ($gerer && $site->estModifiable())
        <x-admin.bouton variante="secondaire" taille="petit" :href="route('admin.sites.edit', $site)" aria-label="{{ $l['modifier'] }} {{ $site->nom }}">{{ $l['modifier'] }}</x-admin.bouton>
    @endif
</div>
