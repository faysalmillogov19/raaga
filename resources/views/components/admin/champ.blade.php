@props(['nom', 'libelle', 'type' => 'text', 'valeur' => null, 'aide' => null])

@php($erreur = $errors->first($nom))
<div {{ $attributes->only('class')->merge(['class' => 'formulaire-champ !mt-0']) }}>
    <label for="{{ $nom }}">{{ $libelle }}</label>
    <input id="{{ $nom }}" name="{{ $nom }}" type="{{ $type }}" value="{{ old($nom, $valeur) }}"
           {{ $attributes->except('class')->merge(['class' => 'champ'.($erreur ? ' est-invalide' : '')]) }}
           @if ($aide || $erreur) aria-describedby="{{ $nom }}-aide" @endif @if ($erreur) aria-invalid="true" @endif>
    @if ($aide)
        <p id="{{ $nom }}-aide" class="aide-champ">{{ $aide }}</p>
    @endif
    @if ($erreur)
        <p class="message-erreur" role="alert">{{ $erreur }}</p>
    @endif
</div>
