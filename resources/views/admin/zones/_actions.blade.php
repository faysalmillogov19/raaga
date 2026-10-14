{{-- Actions d'une ligne des listes Niveaux et Zones : fiche (modification ou lecture), suppression si l'élément n'est ni « par défaut » ni utilisé. --}}
@php
    $z = __('admin.zones');
    $liste = $el instanceof \App\Models\Niveau ? 'niveaux' : 'zones';
    $gerer = auth()->user()->can(\App\Enums\Droit::SitesGerer->value);
    $supprimable = ! $el->par_defaut && $el->nombreUtilisations() === 0;
@endphp
<div class="flex justify-end gap-2">
    <x-admin.bouton :variante="$gerer ? 'secondaire' : 'contour'" taille="petit" :href="route('admin.referencement.zones.show', [$liste, $el->id])"
                    aria-label="{{ ($gerer ? $z['modifier'] : $z['voir']).' '.$el->code }}">{{ $gerer ? $z['modifier'] : $z['voir'] }}</x-admin.bouton>
    @if ($gerer && $supprimable)
        <x-admin.confirmation id="supprimer-{{ $liste }}-{{ $el->id }}" taille="petit" danger :titre="$z['supprimer'].' '.$el->code" :message="$z['confirmer_suppression']"
                              :action="route('admin.referencement.zones.destroy', [$liste, $el->id])" methode="DELETE" :bouton="$z['supprimer']">
            <x-slot:declencheur>{{ $z['supprimer'] }}</x-slot:declencheur>
        </x-admin.confirmation>
    @endif
</div>
