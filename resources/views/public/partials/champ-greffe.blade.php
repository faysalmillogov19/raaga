{{--
    Greffe d'immatriculation au RCCM (05/11/2026) : liste du SUPER_ADMIN, « Autre (préciser) » en dernier avec un champ
    texte. Variables : $valeurId, $valeurAutre, $obligatoire (astérisque), $desactive (expression Alpine, facultative).
--}}
@php
    $rc = __('public.rccm');
    $greffes = \App\Models\Greffe::proposes();
    $idAutre = \App\Models\Greffe::idAutre();
    $choisi = (string) ($valeurId ?? '');
    $desactive ??= null;
@endphp
<div x-data="{ greffe: @js($choisi) }" data-champ-greffe>
    <label for="rccm_greffe_id">@if ($obligatoire)<x-requis /> @endif{{ $rc['greffe'] }}</label>
    <select id="rccm_greffe_id" name="rccm_greffe_id" x-model="greffe" @if ($desactive) :disabled="{{ $desactive }}" @endif
            class="champ @error('rccm_greffe_id') est-invalide @enderror" aria-describedby="rccm_greffe_id-erreur">
        <option value="">{{ $rc['greffe_choisir'] }}</option>
        @foreach ($greffes as $id => $libelle)
            <option value="{{ $id }}" @selected($choisi === (string) $id)>{{ $libelle }}</option>
        @endforeach
    </select>
    @error('rccm_greffe_id')<p id="rccm_greffe_id-erreur" class="message-erreur" role="alert">{{ $message }}</p>@enderror
    <div class="mt-3" x-show="greffe === @js((string) $idAutre)" @if ($choisi !== (string) $idAutre) x-cloak @endif>
        <label for="rccm_greffe_autre"><x-requis /> {{ $rc['greffe_autre'] }}</label>
        <input id="rccm_greffe_autre" name="rccm_greffe_autre" type="text" maxlength="120" autocomplete="off" value="{{ $valeurAutre ?? '' }}"
               @if ($desactive) :disabled="{{ $desactive }}" @endif class="champ @error('rccm_greffe_autre') est-invalide @enderror" aria-describedby="rccm_greffe_autre-erreur">
        @error('rccm_greffe_autre')<p id="rccm_greffe_autre-erreur" class="message-erreur" role="alert">{{ $message }}</p>@enderror
    </div>
</div>
