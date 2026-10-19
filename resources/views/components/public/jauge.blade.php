@props(['valeur', 'max', 'libelle' => null, 'couleur' => 'vif'])

{{-- Jauge du thème « nuit » : libellé au-dessus, piste bordure, remplissage vert vif (barre de progression accessible). --}}
@php($pct = $max > 0 ? min(100, round($valeur / $max * 100, 1)) : 0)
<div {{ $attributes->class(['nuit-jauge']) }}>
    @if ($libelle)<p class="nuit-jauge-libelle">{{ $libelle }}</p>@endif
    <div class="nuit-jauge-piste" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $max }}" aria-valuenow="{{ $valeur }}"
         @if ($libelle) aria-label="{{ $libelle }}" @endif>
        <span class="nuit-jauge-barre nuit-c-{{ \App\Support\CouleursNuit::nom($couleur) }}" style="--pct: {{ $pct }}%"></span>
    </div>
</div>
