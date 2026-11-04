{{-- Actions d'une ligne des secteurs d'activité : modifier, activer ou désactiver (jamais « Autre activité »). --}}
@php
    $s = __('admin.secteurs');
    $familles = once(fn () => \App\Models\FamilleSecteur::query()->orderBy('ordre')->pluck('libelle', 'id')->all());
@endphp
<div class="flex justify-end gap-2">
    <x-admin.fenetre :id="'modifier-secteur-'.$secteur->id" :titre="$s['modifier'].' · '.$secteur->libelle" :action="route('admin.secteurs.update', $secteur)" methode="PUT"
                     declencheur-variante="secondaire" declencheur-taille="petit" :declencheur-libelle="$s['modifier'].' '.$secteur->libelle">
        <x-slot:declencheur>{{ $s['modifier'] }}</x-slot:declencheur>
        @include('admin.secteurs._champs', ['secteur' => $secteur, 'prefixe' => 'secteur-'.$secteur->id, 'familles' => $familles])
    </x-admin.fenetre>
    @if ($secteur->actif)
        @unless ($secteur->code === \App\Models\SecteurActivite::AUTRE)
            <x-admin.confirmation :id="'desactiver-secteur-'.$secteur->id" taille="petit" :titre="$s['desactiver'].' · '.$secteur->libelle" :message="$s['confirmer_desactivation']"
                                  :action="route('admin.secteurs.desactiver', $secteur)" :bouton="$s['desactiver']" :libelle="$s['desactiver'].' '.$secteur->libelle">
                <x-slot:declencheur>{{ $s['desactiver'] }}</x-slot:declencheur>
            </x-admin.confirmation>
        @endunless
    @else
        <x-admin.confirmation :id="'activer-secteur-'.$secteur->id" taille="petit" :titre="$s['activer'].' · '.$secteur->libelle" :message="$s['confirmer_activation']"
                              :action="route('admin.secteurs.activer', $secteur)" :bouton="$s['activer']" :libelle="$s['activer'].' '.$secteur->libelle">
            <x-slot:declencheur>{{ $s['activer'] }}</x-slot:declencheur>
        </x-admin.confirmation>
    @endif
</div>
