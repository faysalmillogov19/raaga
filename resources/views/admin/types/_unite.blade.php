{{-- Unité du loyer. Variables : $valeur (unité actuelle ou null), $verrouille (une grille confirmée utilise le type). --}}
@php($t = __('admin.types'))
<div class="formulaire-champ !mt-0">
    <label for="unite_loyer">{{ $t['champs']['unite_loyer'] }}</label>
    <select id="unite_loyer" name="unite_loyer" class="champ @error('unite_loyer') est-invalide @enderror" required
            @if ($verrouille) aria-describedby="unite-aide" @endif>
        @foreach (\App\Enums\UniteLoyer::cases() as $unite)
            <option value="{{ $unite->value }}" @selected(old('unite_loyer', $valeur?->value ?? \App\Enums\UniteLoyer::M2->value) === $unite->value)
                    @disabled($verrouille && $unite !== $valeur)>{{ $unite->label() }}</option>
        @endforeach
    </select>
    @if ($verrouille)<p id="unite-aide" class="aide-champ">{{ $t['unite_figee'] }}</p>@endif
    @error('unite_loyer') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
</div>
