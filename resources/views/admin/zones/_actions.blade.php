{{-- Boutons d'action d'une ligne des listes Niveaux et Zones. --}}
@php
    $z = __('admin.zones');
    $liste = $el instanceof \App\Models\Niveau ? 'niveaux' : 'zones';
    $gerer = auth()->user()->can(\App\Enums\Droit::SitesGerer->value);
    $supprimable = ! $el->par_defaut && $el->nombreUtilisations() === 0;
@endphp
<div class="flex gap-2">
    <x-admin.bouton-icone :href="route('admin.referencement.zones.show', [$liste, $el->id])" :icone="$gerer ? 'modifier' : 'voir'"
                          :intitule="($gerer ? $z['modifier'] : $z['voir']).' '.$el->code" :infobulle="$gerer ? $z['modifier'] : $z['voir']" />
    @if ($gerer)
        <form method="POST" action="{{ route('admin.referencement.zones.destroy', [$liste, $el->id]) }}">
            @csrf @method('DELETE')
            <x-admin.bouton-icone type="submit" icone="supprimer" variante="danger" :intitule="$z['supprimer'].' '.$el->code" :disabled="! $supprimable"
                                  :infobulle="$supprimable ? $z['supprimer'] : ($el->par_defaut ? $z['suppression_defaut'] : $z['suppression_utilise'])" />
        </form>
    @endif
</div>
