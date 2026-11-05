{{-- Recette (05/11/2026) : accès à la page « SMS envoyés » par le mot de passe commun des testeurs. N'existe qu'en recette. --}}
@php($titre = __('public.recette_sms.titre'))
@php($a = __('public.recette_sms.acces'))

<x-public.layout :titre="$titre">
    <x-public.page-entete :titre="$titre" :intro="$a['intro']" :fil="[['libelle' => $titre]]" />

    <div class="conteneur max-w-xl py-8">
        <form method="POST" action="{{ route('recette.sms.acceder') }}" class="carte p-5" novalidate data-acces-sms-recette>
            @csrf
            <label for="mot_de_passe" class="block font-semibold text-encre"><x-requis /> {{ $a['mot_de_passe'] }}</label>
            <input id="mot_de_passe" name="mot_de_passe" type="password" autocomplete="current-password" required class="champ mt-2"
                   @error('mot_de_passe') aria-invalid="true" aria-describedby="mot_de_passe-erreur" @enderror>
            <x-public.erreur :messages="$errors->get('mot_de_passe')" id="mot_de_passe-erreur" />
            <button type="submit" class="btn-primaire mt-4">{{ $a['bouton'] }}</button>
        </form>
    </div>
</x-public.layout>
