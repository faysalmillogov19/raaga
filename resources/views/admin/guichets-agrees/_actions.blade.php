{{-- Actions d'une ligne des guichets agréés : modifier, activer ou désactiver, supprimer. --}}
@php
    $g = __('admin.guichets_agrees');
    $communes = once(fn () => \App\Models\Commune::parProvince());
@endphp
<div class="flex justify-end gap-2">
    <x-admin.fenetre :id="'modifier-guichet-'.$guichet->id" :titre="$g['modifier'].' · '.$guichet->nom" :action="route('admin.guichets-agrees.update', $guichet)" methode="PUT"
                     declencheur-variante="secondaire" declencheur-taille="petit" :declencheur-libelle="$g['modifier'].' '.$guichet->nom">
        <x-slot:declencheur>{{ $g['modifier'] }}</x-slot:declencheur>
        @include('admin.guichets-agrees._champs', ['guichet' => $guichet, 'prefixe' => 'guichet-'.$guichet->id, 'communes' => $communes])
    </x-admin.fenetre>
    @if ($guichet->actif)
        <x-admin.confirmation :id="'desactiver-guichet-'.$guichet->id" taille="petit" :titre="$g['desactiver'].' · '.$guichet->nom" :message="$g['confirmer_desactivation']"
                              :action="route('admin.guichets-agrees.desactiver', $guichet)" :bouton="$g['desactiver']" :libelle="$g['desactiver'].' '.$guichet->nom">
            <x-slot:declencheur>{{ $g['desactiver'] }}</x-slot:declencheur>
        </x-admin.confirmation>
    @else
        <x-admin.confirmation :id="'activer-guichet-'.$guichet->id" taille="petit" :titre="$g['activer'].' · '.$guichet->nom" :message="$g['confirmer_activation']"
                              :action="route('admin.guichets-agrees.activer', $guichet)" :bouton="$g['activer']" :libelle="$g['activer'].' '.$guichet->nom">
            <x-slot:declencheur>{{ $g['activer'] }}</x-slot:declencheur>
        </x-admin.confirmation>
    @endif
    <x-admin.confirmation :id="'supprimer-guichet-'.$guichet->id" taille="petit" danger :titre="$g['supprimer'].' · '.$guichet->nom" :message="$g['confirmer_suppression']"
                          :action="route('admin.guichets-agrees.destroy', $guichet)" methode="DELETE" :bouton="$g['supprimer']" :libelle="$g['supprimer'].' '.$guichet->nom">
        <x-slot:declencheur>{{ $g['supprimer'] }}</x-slot:declencheur>
    </x-admin.confirmation>
</div>
