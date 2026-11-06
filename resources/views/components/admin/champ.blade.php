@props([
    'nom', 'libelle', 'type' => 'text', 'valeur' => null, 'aide' => null, 'obligatoire' => false,
    'options' => [], 'vide' => null, 'lignes' => 3, 'id' => null, 'recherche' => false, 'nip' => false,
])

{{--
    Champ de formulaire du back-office : libellé (astérisque devant si obligatoire), aide, erreur (bordure 2 px, message
    sous le champ, aria-invalid). Types : text (et number, email, date…), liste, zone (textarea), radio, case,
    interrupteur (role="switch"), verrouille (valeur affichée, non modifiable). Liste : une option dont la valeur est un
    tableau devient un groupe (optgroup) de ses options.
    Liste avec « recherche » (communes, 06/11/2026) : champ de saisie avec suggestions, la liste reste la valeur envoyée.
    « nip » (06/11/2026) : numéro de la pièce d'identité ; CNIB ou CIB-AES choisie (variable Alpine « piece » du
    formulaire), libellé « NIP de la CNIB », texte indicatif et contrôle des 17 chiffres (App\Support\Nip).
--}}
@php
    $id ??= str_replace(['[', ']', '.'], ['-', '', '-'], $nom);
    $erreur = ($errors ?? new \Illuminate\Support\ViewErrorBag)->first(str_replace(['[', ']'], ['.', ''], $nom));
    $obligatoire = $obligatoire || $attributes->has('required');
    $courante = old(str_replace(['[', ']'], ['.', ''], $nom), $valeur instanceof \BackedEnum ? $valeur->value : $valeur);
    $decrit = trim(($aide ? $id.'-aide ' : '').($erreur ? $id.'-erreur' : ''));
    $controle = $attributes->except(['class', 'required'])->merge(['class' => 'champ'.($erreur ? ' est-invalide' : '')]);
    $tn = $nip ? \App\Support\Nip::textes() : null;
    // Libellé du NIP selon la pièce choisie (directives Blade jamais collées : variable préparée ici).
    $libelleAffiche = $nip ? new \Illuminate\Support\HtmlString('<span x-text="('.e(\Illuminate\Support\Js::from($tn['libelles'])).')[piece] ?? '.e(\Illuminate\Support\Js::from($libelle)).'">'.e($libelle).'</span>') : $libelle;
@endphp
<div {{ $attributes->only('class')->merge(['class' => 'formulaire-champ adm-champ !mt-0']) }} @if ($nip) x-data="{ nipValeur: @js((string) $courante), nipTouche: false }" data-champ-nip @endif>
    @if (in_array($type, ['radio'], true))
        <fieldset @if ($decrit) aria-describedby="{{ $decrit }}" @endif>
            <legend class="adm-libelle">@if ($obligatoire)<x-requis /> @endif{{ $libelle }}</legend>
            <div class="adm-choix">
                @foreach ($options as $cle => $texte)
                    <label class="adm-option">
                        <input type="radio" name="{{ $nom }}" value="{{ $cle }}" @checked((string) $courante === (string) $cle) @required($obligatoire)
                               @if ($erreur) aria-invalid="true" @endif>
                        <span>{{ $texte }}</span>
                    </label>
                @endforeach
            </div>
        </fieldset>
    @elseif (in_array($type, ['case', 'interrupteur'], true))
        <input type="hidden" name="{{ $nom }}" value="0">
        <label class="adm-option" for="{{ $id }}">
            <input id="{{ $id }}" type="checkbox" name="{{ $nom }}" value="1" @checked((bool) $courante)
                   @if ($type === 'interrupteur') role="switch" class="adm-interrupteur" @endif
                   @if ($decrit) aria-describedby="{{ $decrit }}" @endif @if ($erreur) aria-invalid="true" @endif {{ $attributes->except(['class', 'required']) }}>
            <span>@if ($obligatoire)<x-requis /> @endif{{ $libelle }}</span>
        </label>
    @else
        <label for="{{ $id }}" class="adm-libelle">@if ($obligatoire)<x-requis /> @endif{{ $libelleAffiche }}</label>
        @if ($type === 'liste')
            @if ($recherche)<div class="choix-commune" x-data="choixCommune" @click.outside="fermer()" data-choix-commune><x-choix-commune-saisie :id="$id" />@endif
            <select id="{{ $id }}" name="{{ $nom }}" {{ $controle }} @required($obligatoire)
                    @if ($decrit) aria-describedby="{{ $decrit }}" @endif @if ($erreur) aria-invalid="true" @endif>
                @if ($vide !== null)<option value="">{{ $vide }}</option>@endif
                @foreach ($options as $cle => $texte)
                    @if (is_array($texte))
                        <optgroup label="{{ $cle }}">
                            @foreach ($texte as $sousCle => $sousTexte)
                                <option value="{{ $sousCle }}" @selected((string) $courante === (string) $sousCle)>{{ $sousTexte }}</option>
                            @endforeach
                        </optgroup>
                    @else
                        <option value="{{ $cle }}" @selected((string) $courante === (string) $cle)>{{ $texte }}</option>
                    @endif
                @endforeach
            </select>
            @if ($recherche)</div>@endif
        @elseif ($type === 'zone')
            <textarea id="{{ $id }}" name="{{ $nom }}" rows="{{ $lignes }}" {{ $controle }} @required($obligatoire)
                      @if ($decrit) aria-describedby="{{ $decrit }}" @endif @if ($erreur) aria-invalid="true" @endif>{{ $courante }}</textarea>
        @elseif ($type === 'verrouille')
            <input id="{{ $id }}" type="text" value="{{ $courante }}" readonly aria-readonly="true" class="champ adm-verrouille"
                   @if ($decrit) aria-describedby="{{ $decrit }}" @endif>
        @else
            <input id="{{ $id }}" name="{{ $nom }}" type="{{ $type }}" value="{{ $courante }}" {{ $controle }} @required($obligatoire)
                   @if ($decrit) aria-describedby="{{ $decrit }}" @endif @if ($erreur) aria-invalid="true" @endif
                   @if ($nip) x-model="nipValeur" @blur="nipTouche = true" :placeholder="(@js($tn['indications']))[piece] ?? ''" :inputmode="(@js($tn['libelles']))[piece] ? 'numeric' : 'text'" @endif>
        @endif
    @endif
    @if ($aide)
        <p id="{{ $id }}-aide" class="aide-champ">{{ $aide }}</p>
    @endif
    @if ($nip)
        <p x-show="(@js($tn['erreurs']))[piece] && nipTouche && nipValeur.trim() !== '' && ! /^\d{17}$/.test(nipValeur.replace(/[\s.\-]/g, ''))" x-text="(@js($tn['erreurs']))[piece]" x-cloak class="message-erreur" role="alert"></p>
    @endif
    @if ($erreur)
        <p id="{{ $id }}-erreur" class="message-erreur" role="alert">{{ $erreur }}</p>
    @endif
</div>
