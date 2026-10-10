@props(['nom', 'libelle', 'options' => [], 'valeurs' => []])

{{-- Choix multiple : $options = [valeur => libellé], envoyé sous la forme nom[]. --}}
@php
    $erreur = $errors->first($nom) ?: $errors->first($nom.'.*');
    $cochees = array_map('strval', (array) old($nom, $valeurs));
@endphp
<fieldset {{ $attributes->merge(['class' => 'formulaire-champ !mt-0']) }}>
    <legend class="formulaire-libelle">{{ $libelle }}</legend>
    <div class="flex flex-wrap gap-x-5 gap-y-1">
        @foreach ($options as $cle => $texte)
            <label class="inline-flex min-h-[44px] cursor-pointer items-center gap-2">
                <input type="checkbox" name="{{ $nom }}[]" value="{{ $cle }}" @checked(in_array((string) $cle, $cochees, true))
                       class="h-5 w-5 rounded border-2 border-gris text-vert">
                <span>{{ $texte }}</span>
            </label>
        @endforeach
    </div>
    @if ($erreur)
        <p class="message-erreur" role="alert">{{ $erreur }}</p>
    @endif
</fieldset>
