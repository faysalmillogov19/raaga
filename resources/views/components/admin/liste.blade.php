@props(['nom', 'libelle', 'options' => [], 'valeur' => null, 'aide' => null, 'vide' => null])

{{-- Liste déroulante : relais vers <x-admin.champ type="liste">. $options = [valeur => libellé]. --}}
<x-admin.champ type="liste" :nom="$nom" :libelle="$libelle" :options="$options" :valeur="$valeur" :aide="$aide" :vide="$vide" {{ $attributes }} />
