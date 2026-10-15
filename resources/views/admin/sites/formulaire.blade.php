@php
    $c = __('admin.sites.champs');
    $f = __('admin.sites.formulaire');
    $titre = $site->exists ? $f['modification'] : $f['creation'];
    $fil = [['libelle' => __('admin.sites.titre'), 'url' => route('admin.sites')]];
    if ($site->exists) {
        $fil[] = ['libelle' => $site->nom, 'url' => route('admin.sites.show', $site)];
    }
    $fil[] = ['libelle' => $titre];
@endphp

<x-admin.layout :titre="$titre" :fil="$fil">
    <form method="POST" action="{{ $site->exists ? route('admin.sites.update', $site) : route('admin.sites.store') }}" class="space-y-[18px]" novalidate>
        @csrf
        @if ($site->exists) @method('PUT') @endif

        {{-- Identification et localisation --}}
        <section class="bo-carte !mt-0" aria-labelledby="s-identite">
            <h2 id="s-identite" class="!text-[1.15rem]">{{ $f['sections']['identite'] }}</h2>
            <div class="mt-3 grid gap-4 lg:grid-cols-2">
                @if ($site->exists && $site->code !== null && $site->codeFige())
                    <x-admin.champ nom="code" :libelle="ucfirst($c['code'])" :valeur="$site->code" readonly :aide="$f['code_fige']" />
                @else
                    <x-admin.champ nom="code" :libelle="ucfirst($c['code'])" :valeur="$site->code" required minlength="3" maxlength="3" autocomplete="off" :aide="$f['code_aide']" />
                @endif
                <x-admin.champ nom="nom" :libelle="ucfirst($c['nom'])" :valeur="$site->nom" required maxlength="150" />
                <x-admin.champ nom="ville" :libelle="ucfirst($c['ville'])" :valeur="$site->localite()" required maxlength="80" />
                <x-admin.champ nom="arrondissement" :libelle="ucfirst($c['arrondissement'])" :valeur="$site->arrondissement" maxlength="40" />
                <div class="formulaire-champ !mt-0">
                    <label for="region">{{ ucfirst($c['region']) }}</label>
                    <select id="region" name="region" class="champ @error('region') est-invalide @enderror">
                        <option value="">{{ $f['choisir'] }}</option>
                        @foreach (config('raaga.inscription.regions') as $region)
                            <option value="{{ $region }}" @selected(old('region', $site->region) === $region)>{{ $region }}</option>
                        @endforeach
                    </select>
                    @error('region') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
                </div>
                <x-admin.champ nom="adresse" :libelle="ucfirst($c['adresse'])" :valeur="$site->adresse" maxlength="300" />
                <x-admin.champ nom="latitude" :libelle="ucfirst($c['latitude'])" :valeur="$site->latitude" type="number" step="0.0000001" min="-90" max="90" />
                <x-admin.champ nom="longitude" :libelle="ucfirst($c['longitude'])" :valeur="$site->longitude" type="number" step="0.0000001" min="-180" max="180" />
            </div>
        </section>

        {{-- Projet : maîtrise d'ouvrage, phase, plans --}}
        <section class="bo-carte !mt-0" aria-labelledby="s-projet">
            <h2 id="s-projet" class="!text-[1.15rem]">{{ $f['sections']['projet'] }}</h2>
            <div class="mt-3 grid gap-4 lg:grid-cols-2">
                <x-admin.champ nom="maitre_ouvrage" :libelle="ucfirst($c['maitre_ouvrage'])" :valeur="$site->maitre_ouvrage" maxlength="200" />
                <x-admin.champ nom="maitre_oeuvre" :libelle="ucfirst($c['maitre_oeuvre'])" :valeur="$site->maitre_oeuvre" maxlength="200" />
                <div class="formulaire-champ !mt-0">
                    <label for="phase">{{ ucfirst($c['phase']) }}</label>
                    <select id="phase" name="phase" class="champ @error('phase') est-invalide @enderror">
                        <option value="">{{ $f['choisir'] }}</option>
                        @foreach (\App\Enums\PhaseSite::cases() as $phase)
                            <option value="{{ $phase->value }}" @selected(old('phase', $site->phase?->value) === $phase->value)>{{ $phase->label() }}</option>
                        @endforeach
                    </select>
                    @error('phase') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
                </div>
                <x-admin.champ nom="livraison_prevue" :libelle="ucfirst($c['livraison_prevue'])" :valeur="$site->livraison_prevue?->format('Y-m')" type="month" />
                <x-admin.champ nom="reference_plans" :libelle="ucfirst($c['reference_plans'])" :valeur="$site->reference_plans" maxlength="200" />
                <x-admin.champ nom="version_plans" :libelle="ucfirst($c['version_plans'])" :valeur="$site->version_plans" maxlength="60" />
            </div>
        </section>

        {{-- Sources et description (les données financières sont saisies par le RÉGULATEUR, à part) --}}
        <section class="bo-carte !mt-0" aria-labelledby="s-sources">
            <h2 id="s-sources" class="!text-[1.15rem]">{{ $f['sections']['sources'] }}</h2>
            <div class="mt-3 grid gap-4 lg:grid-cols-2">
                <x-admin.champ nom="source_donnees" :libelle="ucfirst($c['source_donnees'])" :valeur="$site->source_donnees" maxlength="300" />
                <div class="formulaire-champ !mt-0 lg:col-span-2">
                    <label for="description">{{ ucfirst($c['description']) }}</label>
                    <textarea id="description" name="description" rows="3" maxlength="2000" class="champ">{{ old('description', $site->description) }}</textarea>
                </div>
            </div>
        </section>

        <div class="flex flex-wrap gap-3">
            <x-admin.enregistrer-soumettre />
            <x-admin.bouton variante="contour" href="{{ $site->exists ? route('admin.sites.show', $site) : route('admin.sites') }}">{{ __('admin.tranches.formulaire.annuler') }}</x-admin.bouton>
        </div>
    </form>
</x-admin.layout>
