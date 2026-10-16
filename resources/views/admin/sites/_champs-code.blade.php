{{-- Éléments du code boutique hors numéro : niveau et bloc. Variables : $prefixe (identifiants), $boutique (valeurs, ou null). --}}
@php($b = __('admin.sites.boutiques'))
<div class="formulaire-champ !mt-0">
    <label for="{{ $prefixe }}-niveau"><span class="adm-requis" aria-hidden="true">*</span> {{ $b['niveau'] }}</label>
    <select id="{{ $prefixe }}-niveau" name="niveau" class="champ @error('niveau') est-invalide @enderror" required>
        <option value="">{{ __('admin.sites.formulaire.choisir') }}</option>
        {{-- Niveaux validés et actifs, plus le niveau actuel de la boutique s'il a été désactivé. --}}
        @foreach (\App\Models\Niveau::query()->where(fn ($q) => $q->utilisables()->orWhere('code', $boutique?->niveau ?? ''))->orderBy('rang')->get() as $niveau)
            <option value="{{ $niveau->code }}" @selected(old('niveau', $boutique?->niveau) === $niveau->code)>{{ $niveau->libelle }}</option>
        @endforeach
    </select>
    @error('niveau') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
</div>
<div class="formulaire-champ !mt-0">
    <label for="{{ $prefixe }}-bloc">{{ $b['bloc'] }}</label>
    <input id="{{ $prefixe }}-bloc" name="bloc" value="{{ old('bloc', $boutique?->bloc) }}" maxlength="5" required autocomplete="off"
           class="champ uppercase @error('bloc') est-invalide @enderror" aria-describedby="{{ $prefixe }}-bloc-aide">
    <p id="{{ $prefixe }}-bloc-aide" class="aide-champ">{{ $b['bloc_aide'] }}</p>
    @error('bloc') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
</div>
