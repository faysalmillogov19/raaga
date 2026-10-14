{{-- Boutons d'action d'une ligne de la liste des boutiques. --}}
@php
    $b = __('admin.referencement.boutiques');
    $gerer = auth()->user()->can(\App\Enums\Droit::SitesGerer->value);
    $motif = ! $boutique->site->estModifiable() ? $b['site_fige'] : ($boutique->statut->verrouillee() ? $b['verrouillee'] : null);
@endphp
<div class="flex gap-2">
    <x-admin.bouton-icone :href="route('admin.referencement.boutiques.show', $boutique)" icone="voir" :intitule="$b['voir'].' '.$boutique->reference()" :infobulle="$b['voir']" />
    @if ($gerer)
        @if ($motif)
            <x-admin.bouton-icone icone="modifier" :intitule="$b['modifier_court'].' '.$boutique->reference()" :infobulle="$motif" disabled />
        @else
            <x-admin.bouton-icone :href="route('admin.referencement.boutiques.edit', $boutique)" icone="modifier" :intitule="$b['modifier_court'].' '.$boutique->reference()" :infobulle="$b['modifier_court']" />
        @endif
        <form method="POST" action="{{ route('admin.referencement.boutiques.destroy', $boutique) }}">
            @csrf @method('DELETE')
            <x-admin.bouton-icone type="submit" icone="supprimer" variante="danger" :intitule="$b['supprimer'].' '.$boutique->reference()" :disabled="$motif !== null"
                                  :infobulle="$motif ?? $b['supprimer']" />
        </form>
    @endif
</div>
