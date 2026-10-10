@props(['nom', 'libelle', 'options' => [], 'valeur' => null])

{{-- Liste déroulante. $options = [valeur => libellé, …] --}}
@php
    $id = $attributes->get('id', $nom);
    $erreurs = $errors->get($nom);
    $selection = (string) old($nom, $valeur);
@endphp

<div {{ $attributes->only('class') }}>
    <label for="{{ $id }}" class="block font-semibold text-encre">{{ $libelle }}</label>
    <select id="{{ $id }}" name="{{ $nom }}"
            @if ($erreurs) aria-invalid="true" aria-describedby="{{ $id }}-erreur" @endif
            {{ $attributes->except(['id', 'class'])->merge(['class' => 'mt-1.5 block min-h-[48px] w-full rounded-charte border-2 px-4 text-base text-encre focus:border-vert focus:ring-0 '.($erreurs ? 'border-rouge' : 'border-ligne')]) }}>
        @foreach ($options as $cle => $texte)
            <option value="{{ $cle }}" @selected($selection === (string) $cle)>{{ $texte }}</option>
        @endforeach
    </select>
    <x-public.erreur :messages="$erreurs" :id="$id.'-erreur'" />
</div>
