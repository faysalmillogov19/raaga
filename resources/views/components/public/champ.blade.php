@props(['nom', 'libelle', 'type' => 'text', 'valeur' => null, 'aide' => null, 'obligatoire' => false, 'nip' => false])

{{-- Champ public : astérisque devant le libellé si obligatoire (attribut required, ou « obligatoire » quand seul le serveur l'exige).
     « nip » (06/11/2026) : numéro de la pièce d'identité ; CNIB ou CIB-AES choisie (variable Alpine « piece » du formulaire),
     libellé « NIP de la CNIB », texte indicatif et contrôle des 17 chiffres dès que le champ est quitté (App\Support\Nip). --}}

@php
    $id = $attributes->get('id', $nom);
    $erreurs = $errors->get($nom);
    $decrit = collect([$aide ? "{$id}-aide" : null, $erreurs ? "{$id}-erreur" : null])->filter()->implode(' ');
    $motDePasse = $type === 'password';
    $obligatoire = $obligatoire || $attributes->has('required');
    $tn = $nip ? \App\Support\Nip::textes() : null;
    // Libellé du NIP selon la pièce choisie (directives Blade jamais collées : variable préparée ici).
    $libelleAffiche = $nip ? new \Illuminate\Support\HtmlString('<span x-text="('.e(\Illuminate\Support\Js::from($tn['libelles'])).')[piece] ?? '.e(\Illuminate\Support\Js::from($libelle)).'">'.e($libelle).'</span>') : $libelle;
@endphp

<div {{ $attributes->only('class') }} @if ($nip) x-data="{ nipValeur: @js((string) old($nom, $valeur)), nipTouche: false }" data-champ-nip @endif>
    <label for="{{ $id }}" class="block font-semibold text-encre">@if ($obligatoire)<x-requis /> @endif{{ $libelleAffiche }}</label>
    @if ($aide)
        <p id="{{ $id }}-aide" class="texte-carte">{{ $aide }}</p>
    @endif

    <div class="relative mt-1.5" @if ($motDePasse) x-data="{ visible: false }" @endif>
        <input id="{{ $id }}" name="{{ $nom }}" type="{{ $type }}"
               @if ($motDePasse) :type="visible ? 'text' : 'password'" @else value="{{ old($nom, $valeur) }}" @endif
               @if ($erreurs) aria-invalid="true" @endif
               @if ($decrit) aria-describedby="{{ $decrit }}" @endif
               @if ($nip) x-model="nipValeur" @blur="nipTouche = true" :placeholder="(@js($tn['indications']))[piece] ?? ''" :inputmode="(@js($tn['libelles']))[piece] ? 'numeric' : 'text'" @endif
               {{ $attributes->except(['id', 'class'])->merge(['class' => 'block min-h-[48px] w-full rounded-charte border-2 px-4 text-base text-encre focus:border-vert focus:ring-0 '.($erreurs ? 'border-rouge' : 'border-ligne').($motDePasse ? ' pr-28' : '')]) }}>

        @if ($motDePasse)
            <button type="button" @click="visible = ! visible"
                    class="absolute inset-y-0 right-0 flex min-w-[44px] items-center px-4 text-sm font-semibold text-vert-fonce underline underline-offset-2"
                    :aria-pressed="visible.toString()" aria-pressed="false" aria-controls="{{ $id }}">
                <span x-text="visible ? @js(__('public.auth.masquer')) : @js(__('public.auth.afficher'))">{{ __('public.auth.afficher') }}</span>
            </button>
        @endif
    </div>

    @if ($nip)
        <p x-show="(@js($tn['erreurs']))[piece] && nipTouche && nipValeur.trim() !== '' && ! /^\d{17}$/.test(nipValeur.replace(/[\s.\-]/g, ''))" x-text="(@js($tn['erreurs']))[piece]" x-cloak class="mt-1.5 font-semibold text-rouge" role="alert"></p>
    @endif
    <x-public.erreur :messages="$erreurs" :id="$id.'-erreur'" />
</div>
