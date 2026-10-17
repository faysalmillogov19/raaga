@props(['nom', 'libelle', 'obligatoire' => false])

{{-- Case à cocher avec une zone cliquable d'au moins 44px. Jamais pré-cochée par défaut. --}}
<label for="{{ $attributes->get('id', $nom) }}" {{ $attributes->only('class')->merge(['class' => 'inline-flex min-h-[44px] cursor-pointer items-center gap-3']) }}>
    <input id="{{ $attributes->get('id', $nom) }}" name="{{ $nom }}" type="checkbox"
           {{ $attributes->except(['id', 'class']) }}
           class="h-6 w-6 shrink-0 rounded border-2 border-gris text-vert focus:ring-0">
    <span class="text-texte">@if ($obligatoire || $attributes->has('required'))<x-requis /> @endif{{ $libelle }}</span>
</label>
