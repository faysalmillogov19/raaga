{{-- Fiche de la boutique hors code : type, surface, position, coffret, planche, état physique. Variables : $prefixe, $boutique (ou null). --}}
@php($b = __('admin.sites.boutiques'))
<div class="formulaire-champ !mt-0">
    <label for="{{ $prefixe }}-type"><span class="adm-requis" aria-hidden="true">*</span> {{ $b['type'] }}</label>
    <select id="{{ $prefixe }}-type" name="type_boutique_id" class="champ @error('type_boutique_id') est-invalide @enderror" required>
        @foreach ($types as $type)
            <option value="{{ $type->id }}" @selected((int) old('type_boutique_id', $boutique?->type_boutique_id) === $type->id)>{{ $type->code ? $type->code.' · ' : '' }}{{ $type->libelle }}</option>
        @endforeach
    </select>
    @error('type_boutique_id') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
</div>
<div class="formulaire-champ !mt-0">
    <label for="{{ $prefixe }}-surface">{{ $b['surface'] }}</label>
    <input id="{{ $prefixe }}-surface" name="surface" type="number" step="0.01" min="0.5" value="{{ old('surface', $boutique?->surface) }}"
           class="champ @error('surface') est-invalide @enderror">
    @error('surface') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
</div>
<div class="formulaire-champ !mt-0">
    <label for="{{ $prefixe }}-position">{{ $b['position'] }}</label>
    <select id="{{ $prefixe }}-position" name="position" class="champ">
        <option value="">{{ $b['aucune_position'] }}</option>
        @foreach (\App\Enums\PositionBoutique::cases() as $position)
            <option value="{{ $position->value }}" @selected(old('position', $boutique?->position?->value) === $position->value)>{{ $position->label() }}</option>
        @endforeach
    </select>
    @error('position') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
</div>
<div class="formulaire-champ !mt-0">
    <label for="{{ $prefixe }}-etat"><span class="adm-requis" aria-hidden="true">*</span> {{ $b['etat_physique'] }}</label>
    <select id="{{ $prefixe }}-etat" name="etat_physique" class="champ @error('etat_physique') est-invalide @enderror" required>
        <option value="">{{ __('admin.sites.formulaire.choisir') }}</option>
        @foreach (\App\Enums\EtatPhysiqueBoutique::cases() as $etat)
            <option value="{{ $etat->value }}" @selected(old('etat_physique', $boutique?->etat_physique?->value) === $etat->value)>{{ $etat->label() }}</option>
        @endforeach
    </select>
    @error('etat_physique') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
</div>
<div class="formulaire-champ !mt-0">
    <label for="{{ $prefixe }}-coffret">{{ $b['coffret'] }}</label>
    <input id="{{ $prefixe }}-coffret" name="coffret" maxlength="60" value="{{ old('coffret', $boutique?->coffret) }}" class="champ @error('coffret') est-invalide @enderror">
    @error('coffret') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
</div>
<div class="formulaire-champ !mt-0">
    <label for="{{ $prefixe }}-planche">{{ $b['planche'] }}</label>
    <input id="{{ $prefixe }}-planche" name="planche" maxlength="60" value="{{ old('planche', $boutique?->planche) }}" class="champ @error('planche') est-invalide @enderror">
    @error('planche') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
</div>
<div class="formulaire-champ !mt-0 sm:col-span-2 lg:col-span-3">
    <label for="{{ $prefixe }}-observations">{{ $b['observations'] }}</label>
    <input id="{{ $prefixe }}-observations" name="observations" maxlength="500" value="{{ old('observations', $boutique?->observations) }}" class="champ @error('observations') est-invalide @enderror">
    @error('observations') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
</div>
