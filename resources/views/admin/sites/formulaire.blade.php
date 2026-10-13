@php
    $c = __('admin.sites.champs');
    $titre = $site->exists ? __('admin.sites.formulaire.modification') : __('admin.sites.formulaire.creation');
    $fil = [['libelle' => __('admin.sites.titre'), 'url' => route('admin.sites')]];
    if ($site->exists) {
        $fil[] = ['libelle' => $site->nom, 'url' => route('admin.sites.show', $site)];
    }
    $fil[] = ['libelle' => $titre];
@endphp

<x-admin.layout :titre="$titre" :fil="$fil">
    <form method="POST" action="{{ $site->exists ? route('admin.sites.update', $site) : route('admin.sites.store') }}" class="bo-carte !mt-0 space-y-4" novalidate>
        @csrf
        @if ($site->exists) @method('PUT') @endif

        <div class="grid gap-4 lg:grid-cols-2">
            @if ($site->exists && $site->code !== null && $site->codeFige())
                <x-admin.champ nom="code" :libelle="ucfirst($c['code'])" :valeur="$site->code" readonly :aide="__('admin.sites.formulaire.code_fige')" />
            @else
                <x-admin.champ nom="code" :libelle="ucfirst($c['code'])" :valeur="$site->code" required minlength="3" maxlength="3" autocomplete="off"
                               :aide="__('admin.sites.formulaire.code_aide')" />
            @endif
            <x-admin.champ nom="nom" :libelle="ucfirst($c['nom'])" :valeur="$site->nom" required maxlength="150" class="lg:col-span-2" />
            <div class="formulaire-champ !mt-0">
                <label for="region">{{ ucfirst($c['region']) }}</label>
                <select id="region" name="region" class="champ @error('region') est-invalide @enderror" required>
                    <option value="">{{ __('admin.sites.formulaire.choisir') }}</option>
                    @foreach (config('raaga.inscription.regions') as $region)
                        <option value="{{ $region }}" @selected(old('region', $site->region) === $region)>{{ $region }}</option>
                    @endforeach
                </select>
                @error('region') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
            </div>
            <x-admin.champ nom="commune" :libelle="ucfirst($c['commune'])" :valeur="$site->commune" required maxlength="80" />
            <x-admin.champ nom="adresse" :libelle="ucfirst($c['adresse'])" :valeur="$site->adresse" required maxlength="300" class="lg:col-span-2" />
            <div class="formulaire-champ !mt-0 lg:col-span-2">
                <label for="description">{{ ucfirst($c['description']) }}</label>
                <textarea id="description" name="description" rows="3" maxlength="2000" class="champ">{{ old('description', $site->description) }}</textarea>
            </div>
        </div>

        <div class="flex gap-3">
            <button type="submit" class="bo-bouton bo-bouton-principal">{{ __('admin.tranches.formulaire.enregistrer') }}</button>
            <a href="{{ $site->exists ? route('admin.sites.show', $site) : route('admin.sites') }}" class="bo-bouton bo-bouton-secondaire">{{ __('admin.tranches.formulaire.annuler') }}</a>
        </div>
    </form>
</x-admin.layout>
