{{-- Actions d'une ligne de la liste des boutiques : « Voir » ; « Modifier » seulement si la boutique est libre (site en brouillon, boutique non engagée). --}}
@php
    $b = __('admin.referencement.boutiques');
    $libre = auth()->user()->can(\App\Enums\Droit::SitesGerer->value) && $boutique->site->estModifiable() && ! $boutique->statut->verrouillee();
@endphp
<div class="flex justify-end gap-2">
    <x-admin.bouton variante="contour" taille="petit" :href="route('admin.referencement.boutiques.show', $boutique)" aria-label="{{ $b['voir_court'] }} {{ $boutique->reference() }}">{{ $b['voir_court'] }}</x-admin.bouton>
    @if ($libre)
        <x-admin.bouton variante="secondaire" taille="petit" :href="route('admin.referencement.boutiques.edit', $boutique)" aria-label="{{ $b['modifier_court'] }} {{ $boutique->reference() }}">{{ $b['modifier_court'] }}</x-admin.bouton>
    @endif
</div>
