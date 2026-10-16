@props([
    'nom', 'libelle', 'type' => 'text', 'valeur' => null, 'aide' => null, 'obligatoire' => false,
    'options' => [], 'vide' => null, 'lignes' => 3, 'id' => null,
])

{{--
    Champ de formulaire du back-office : libellé (astérisque devant si obligatoire), aide, erreur (bordure 2 px, message
    sous le champ, aria-invalid). Types : text (et number, email, date…), liste, zone (textarea), radio, case,
    interrupteur (role="switch"), verrouille (valeur affichée, non modifiable).
--}}
@php
    $id ??= str_replace(['[', ']', '.'], ['-', '', '-'], $nom);
    $erreur = ($errors ?? new \Illuminate\Support\ViewErrorBag)->first(str_replace(['[', ']'], ['.', ''], $nom));
    $obligatoire = $obligatoire || $attributes->has('required');
    $courante = old(str_replace(['[', ']'], ['.', ''], $nom), $valeur instanceof \BackedEnum ? $valeur->value : $valeur);
    $decrit = trim(($aide ? $id.'-aide ' : '').($erreur ? $id.'-erreur' : ''));
    $controle = $attributes->except(['class', 'required'])->merge(['class' => 'champ'.($erreur ? ' est-invalide' : '')]);
@endphp
<div {{ $attributes->only('class')->merge(['class' => 'formulaire-champ adm-champ !mt-0']) }}>
    @if (in_array($type, ['radio'], true))
        <fieldset @if ($decrit) aria-describedby="{{ $decrit }}" @endif>
            <legend class="adm-libelle">@if ($obligatoire)<span class="adm-requis" aria-hidden="true">*</span> @endif{{ $libelle }}</legend>
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
            <span>@if ($obligatoire)<span class="adm-requis" aria-hidden="true">*</span> @endif{{ $libelle }}</span>
        </label>
    @else
        <label for="{{ $id }}" class="adm-libelle">@if ($obligatoire)<span class="adm-requis" aria-hidden="true">*</span> @endif{{ $libelle }}</label>
        @if ($type === 'liste')
            <select id="{{ $id }}" name="{{ $nom }}" {{ $controle }} @required($obligatoire)
                    @if ($decrit) aria-describedby="{{ $decrit }}" @endif @if ($erreur) aria-invalid="true" @endif>
                @if ($vide !== null)<option value="">{{ $vide }}</option>@endif
                @foreach ($options as $cle => $texte)
                    <option value="{{ $cle }}" @selected((string) $courante === (string) $cle)>{{ $texte }}</option>
                @endforeach
            </select>
        @elseif ($type === 'zone')
            <textarea id="{{ $id }}" name="{{ $nom }}" rows="{{ $lignes }}" {{ $controle }} @required($obligatoire)
                      @if ($decrit) aria-describedby="{{ $decrit }}" @endif @if ($erreur) aria-invalid="true" @endif>{{ $courante }}</textarea>
        @elseif ($type === 'verrouille')
            <input id="{{ $id }}" type="text" value="{{ $courante }}" readonly aria-readonly="true" class="champ adm-verrouille"
                   @if ($decrit) aria-describedby="{{ $decrit }}" @endif>
        @else
            <input id="{{ $id }}" name="{{ $nom }}" type="{{ $type }}" value="{{ $courante }}" {{ $controle }} @required($obligatoire)
                   @if ($decrit) aria-describedby="{{ $decrit }}" @endif @if ($erreur) aria-invalid="true" @endif>
        @endif
    @endif
    @if ($aide)
        <p id="{{ $id }}-aide" class="aide-champ">{{ $aide }}</p>
    @endif
    @if ($erreur)
        <p id="{{ $id }}-erreur" class="message-erreur" role="alert">{{ $erreur }}</p>
    @endif
</div>
