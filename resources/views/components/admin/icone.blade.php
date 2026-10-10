@props(['nom'])

{{-- Ancien nom conservé : relais vers le composant unique <x-icon>. --}}
<x-icon :name="$nom" {{ $attributes }} />
