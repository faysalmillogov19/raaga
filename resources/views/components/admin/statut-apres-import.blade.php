{{-- Fenêtre « Confirmer l'import » : statut après l'import, choix obligatoire (« Soumettre au VALIDATEUR » par défaut). --}}
@props(['id'])
@php($s = __('admin.imports_validation.choix'))
<fieldset class="mt-3" data-statut-apres>
    <legend class="font-bold text-encre">{{ $s['legende'] }}</legend>
    @foreach (['SOUMETTRE' => $s['soumettre'], 'BROUILLON' => $s['brouillon']] as $valeur => $libelle)
        <label class="mt-1 flex items-start gap-2 font-normal">
            <input type="radio" name="statut_apres" value="{{ $valeur }}" id="{{ $id }}-{{ strtolower($valeur) }}" class="mt-1" required @checked($valeur === 'SOUMETTRE')>
            <span>{{ $libelle }}</span>
        </label>
    @endforeach
</fieldset>
