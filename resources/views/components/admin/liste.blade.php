@props(['nom', 'libelle', 'options' => [], 'valeur' => null, 'aide' => null, 'vide' => null])

{{-- Liste déroulante. $options = [valeur => libellé]. --}}
@php
    $erreur = $errors->first($nom);
    $selection = (string) old($nom, $valeur instanceof \BackedEnum ? $valeur->value : $valeur);
@endphp
<div {{ $attributes->only('class')->merge(['class' => 'formulaire-champ !mt-0']) }}>
    <label for="{{ $nom }}">{{ $libelle }}</label>
    <select id="{{ $nom }}" name="{{ $nom }}" {{ $attributes->except('class')->merge(['class' => 'champ'.($erreur ? ' est-invalide' : '')]) }}>
        @if ($vide !== null)
            <option value="">{{ $vide }}</option>
        @endif
        @foreach ($options as $cle => $texte)
            <option value="{{ $cle }}" @selected($selection === (string) $cle)>{{ $texte }}</option>
        @endforeach
    </select>
    @if ($aide)
        <p class="aide-champ">{{ $aide }}</p>
    @endif
    @if ($erreur)
        <p class="message-erreur" role="alert">{{ $erreur }}</p>
    @endif
</div>
