@props(['icone' => 'info', 'titre', 'lien' => null, 'libelleLien' => null])

{{--
    État vide honnête : icône, titre, une phrase d'explication (slot), un lien utile.
    Tient la place des parcours pas encore ouverts ; jamais de bouton sans action.
--}}
<div {{ $attributes->merge(['class' => 'mx-auto max-w-xl py-6 text-center']) }}>
    <span class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-vert-pale text-vert-fonce" aria-hidden="true">
        <x-icon :name="$icone" class="h-8 w-8" />
    </span>
    <h2 class="mt-5">{{ $titre }}</h2>
    <p class="mt-3 text-lg leading-relaxed">{{ $slot }}</p>
    @if ($lien && $libelleLien)
        <a href="{{ $lien }}" class="btn-secondaire mt-6">{{ $libelleLien }}</a>
    @endif
</div>
