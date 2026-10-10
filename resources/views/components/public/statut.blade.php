@props(['variante' => 'attente'])

{{-- Badge de statut : « attente » (jaune) ou « valide » (vert, validé / alloué). --}}
<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 rounded-full border px-3 py-0.5 text-sm font-semibold '.match ($variante) {
    'valide' => 'border-vert bg-vert-pale text-vert-fonce',
    default => 'border-jaune bg-jaune-pale text-encre',
}]) }}>
    @if ($variante === 'valide')
        <x-public.icone nom="check" class="h-4 w-4" />
    @endif
    {{ $slot }}
</span>
