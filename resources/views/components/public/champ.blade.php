@props(['nom', 'libelle', 'type' => 'text', 'valeur' => null, 'aide' => null, 'obligatoire' => false])

{{-- Champ public : astérisque devant le libellé si obligatoire (attribut required, ou « obligatoire » quand seul le serveur l'exige). --}}

@php
    $id = $attributes->get('id', $nom);
    $erreurs = $errors->get($nom);
    $decrit = collect([$aide ? "{$id}-aide" : null, $erreurs ? "{$id}-erreur" : null])->filter()->implode(' ');
    $motDePasse = $type === 'password';
    $obligatoire = $obligatoire || $attributes->has('required');
@endphp

<div {{ $attributes->only('class') }}>
    <label for="{{ $id }}" class="block font-semibold text-encre">@if ($obligatoire)<span class="champ-requis" aria-hidden="true">*</span> @endif{{ $libelle }}</label>
    @if ($aide)
        <p id="{{ $id }}-aide" class="texte-carte">{{ $aide }}</p>
    @endif

    <div class="relative mt-1.5" @if ($motDePasse) x-data="{ visible: false }" @endif>
        <input id="{{ $id }}" name="{{ $nom }}" type="{{ $type }}"
               @if ($motDePasse) :type="visible ? 'text' : 'password'" @else value="{{ old($nom, $valeur) }}" @endif
               @if ($erreurs) aria-invalid="true" @endif
               @if ($decrit) aria-describedby="{{ $decrit }}" @endif
               {{ $attributes->except(['id', 'class'])->merge(['class' => 'block min-h-[48px] w-full rounded-charte border-2 px-4 text-base text-encre focus:border-vert focus:ring-0 '.($erreurs ? 'border-rouge' : 'border-ligne').($motDePasse ? ' pr-28' : '')]) }}>

        @if ($motDePasse)
            <button type="button" @click="visible = ! visible"
                    class="absolute inset-y-0 right-0 flex min-w-[44px] items-center px-4 text-sm font-semibold text-vert-fonce underline underline-offset-2"
                    :aria-pressed="visible.toString()" aria-pressed="false" aria-controls="{{ $id }}">
                <span x-text="visible ? @js(__('public.auth.masquer')) : @js(__('public.auth.afficher'))">{{ __('public.auth.afficher') }}</span>
            </button>
        @endif
    </div>

    <x-public.erreur :messages="$erreurs" :id="$id.'-erreur'" />
</div>
