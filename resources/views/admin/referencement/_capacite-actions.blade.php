{{-- Actions d'une capacité : « Voir les boutiques correspondantes » ; modifier et supprimer seulement pour le GESTIONNAIRE, sur un site en brouillon ou en révision. --}}
@php
    $k = __('admin.referencement.capacites');
    $gerer = auth()->user()->can(\App\Enums\Droit::SitesGerer->value);
    $modifiable = $cap->site->estModifiable();
    $niveau = $cap->niveau ? $cap->niveau.' · '.($cap->libelleNiveau() ?? '') : $k['tous_niveaux'];
    $nom = ($cap->site->code ? $cap->site->code.' · ' : '').$cap->site->nom.' · '.$niveau.' · '.($cap->type->code ? $cap->type->code.' · ' : '').$cap->type->libelle;
@endphp
<div class="flex gap-2">
    <x-admin.bouton-icone :href="route('admin.referencement.boutiques', array_filter(['site' => $cap->site_id, 'niveau' => $cap->niveau, 'zone' => $cap->zone, 'type' => $cap->type_boutique_id]))"
                          icone="voir" :intitule="$k['voir'].' : '.$nom" :infobulle="$k['voir']" />
    @if ($gerer)
        @if ($modifiable)
            <x-admin.bouton-icone :href="route('admin.referencement.capacites.edit', $cap)" :data-fenetre-url="route('admin.referencement.capacites.edit', $cap)" aria-haspopup="dialog" id="modifier-capacite-{{ $cap->id }}-ouvrir" icone="modifier" :intitule="$k['modifier'].' : '.$nom" :infobulle="$k['modifier']" />
            <x-admin.confirmation :id="'supprimer-capacite-'.$cap->id" taille="petit" danger :titre="$k['supprimer'].' : '.$nom" :message="$k['confirmer_suppression']"
                                  :action="route('admin.referencement.capacites.destroy', $cap)" methode="DELETE" :bouton="$k['supprimer']"
                                  icone="supprimer" :libelle="$k['supprimer'].' : '.$nom" />
        @else
            <x-admin.bouton-icone icone="modifier" :intitule="$k['modifier'].' : '.$nom" :infobulle="$k['site_fige']" disabled />
            <x-admin.bouton-icone icone="supprimer" variante="danger" :intitule="$k['supprimer'].' : '.$nom" :infobulle="$k['site_fige']" disabled />
        @endif
    @endif
</div>
