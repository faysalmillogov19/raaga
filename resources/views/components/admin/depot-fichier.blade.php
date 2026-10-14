@props(['nom' => 'fichier', 'libelle', 'formats' => '.xlsx', 'tailleMo' => 5, 'aide' => null, 'id' => null, 'accept' => null])

{{--
    Dépôt de fichier : zone en pointillés, glisser-déposer ou choix du fichier (le champ natif couvre la zone),
    formats et taille rappelés, nom du fichier choisi affiché.
--}}
@php
    $id ??= 'depot-'.$nom;
    $erreur = ($errors ?? new \Illuminate\Support\ViewErrorBag)->first($nom);
    $accept ??= $formats === '.xlsx' ? '.xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : $formats;
@endphp
<div {{ $attributes->only('class')->merge(['class' => 'formulaire-champ adm-champ !mt-0']) }} x-data="{ nomFichier: '', survol: false }">
    <label for="{{ $id }}" class="adm-libelle">{{ $libelle }}</label>
    <div @class(['adm-depot', 'est-invalide' => $erreur]) :class="{ 'adm-depot-survol': survol }">
        <input id="{{ $id }}" name="{{ $nom }}" type="file" accept="{{ $accept }}" class="adm-depot-champ" {{ $attributes->except('class') }}
               aria-describedby="{{ $id }}-aide @if ($erreur) {{ $id }}-erreur @endif" @if ($erreur) aria-invalid="true" @endif
               @change="nomFichier = $event.target.files[0]?.name ?? ''" @dragenter="survol = true" @dragleave="survol = false" @drop="survol = false">
        <p class="adm-depot-titre">{{ __('admin.composants.depot_titre') }}</p>
        <p id="{{ $id }}-aide" class="adm-depot-aide">{{ $aide ?? __('admin.composants.depot_aide', ['formats' => $formats, 'taille' => $tailleMo]) }}</p>
        <span class="adm-btn adm-btn-secondaire adm-btn-petit" aria-hidden="true">{{ __('admin.composants.depot_choisir') }}</span>
        <p class="adm-depot-nom" x-show="nomFichier" x-cloak x-text="nomFichier" aria-live="polite"></p>
    </div>
    @if ($erreur)
        <p id="{{ $id }}-erreur" class="message-erreur" role="alert">{{ $erreur }}</p>
    @endif
</div>
