@php
    use App\Enums\CanalPaiement;

    $c = __('admin.boutiques.champs');
    $k = __('admin.boutiques.categorie');
    $titre = __('admin.boutiques.formulaire.'.$mode);
    $dateHeure = fn ($d) => $d ? \Carbon\Carbon::parse($d)->format('Y-m-d\TH:i') : null;
    $lignes = old('categories', $categories ?: [['libelle' => '', 'description' => '', 'nombre_boutiques' => '', 'caution' => '']]);
    $fil = [['libelle' => __('admin.boutiques.titre'), 'url' => route('admin.boutiques')]];
    if ($campagne->exists) {
        $fil[] = ['libelle' => $campagne->nom, 'url' => route('admin.boutiques.show', $campagne)];
    }
    $fil[] = ['libelle' => $titre];
@endphp

<x-admin.layout :titre="$titre" :fil="$fil">
    <form method="POST" action="{{ $campagne->exists ? route('admin.boutiques.update', $campagne) : route('admin.boutiques.store') }}" class="space-y-[18px]" novalidate>
        @csrf
        @if ($campagne->exists)
            @method('PUT')
        @endif

        <section class="bo-carte !mt-0" aria-labelledby="s-campagne">
            <h2 id="s-campagne" class="!text-[1.15rem]">{{ __('admin.boutiques.sections.campagne') }}</h2>
            <div class="mt-3 grid gap-4 lg:grid-cols-2">
                <x-admin.champ nom="nom" :libelle="ucfirst($c['nom'])" :valeur="$campagne->nom" required maxlength="150" class="lg:col-span-2" />
                <div class="formulaire-champ !mt-0 lg:col-span-2">
                    <label for="description">{{ ucfirst($c['description']) }}</label>
                    <textarea id="description" name="description" rows="3" maxlength="2000" class="champ @error('description') est-invalide @enderror">{{ old('description', $campagne->description) }}</textarea>
                    @error('description') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
                </div>
                <x-admin.champ nom="date_ouverture" :libelle="ucfirst($c['date_ouverture'])" type="datetime-local" :valeur="$dateHeure($campagne->date_ouverture)" required />
                <x-admin.champ nom="date_cloture" :libelle="ucfirst($c['date_cloture'])" type="datetime-local" :valeur="$dateHeure($campagne->date_cloture)" required />
            </div>
        </section>

        {{-- Catégories : une ligne par catégorie (libellé, description, nombre de boutiques, caution) --}}
        <section class="bo-carte !mt-0" aria-labelledby="s-categories"
                 x-data="{ lignes: @js(array_values($lignes)) }">
            <h2 id="s-categories" class="!text-[1.15rem]">{{ $k['titre'] }}</h2>
            <p class="texte-carte mt-1">{{ $k['aide'] }}</p>
            @error('categories') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
            @if ($errors->has('categories.*'))
                <p class="message-erreur" role="alert">{{ __('admin.boutiques.erreurs.categorie_complete') }}</p>
            @endif

            <template x-for="(ligne, i) in lignes" :key="i">
                <fieldset class="mt-4 grid gap-3 rounded-charte border border-ligne p-3 lg:grid-cols-[1.2fr_2fr_1fr_1fr_auto] lg:items-end">
                    <legend class="sr-only" x-text="@js($k['libelle']).concat(' ', i + 1)"></legend>
                    <div class="formulaire-champ !mt-0">
                        <label :for="'cat-libelle-' + i">{{ $k['libelle'] }}</label>
                        <input :id="'cat-libelle-' + i" :name="'categories[' + i + '][libelle]'" x-model="ligne.libelle" maxlength="80" class="champ" required>
                    </div>
                    <div class="formulaire-champ !mt-0">
                        <label :for="'cat-description-' + i">{{ $k['description'] }}</label>
                        <input :id="'cat-description-' + i" :name="'categories[' + i + '][description]'" x-model="ligne.description" maxlength="300" class="champ">
                    </div>
                    <div class="formulaire-champ !mt-0">
                        <label :for="'cat-nombre-' + i">{{ $k['nombre_boutiques'] }}</label>
                        <input :id="'cat-nombre-' + i" :name="'categories[' + i + '][nombre_boutiques]'" x-model="ligne.nombre_boutiques" type="number" min="1" class="champ" required>
                    </div>
                    <div class="formulaire-champ !mt-0">
                        <label :for="'cat-caution-' + i">{{ $k['caution'] }}</label>
                        <input :id="'cat-caution-' + i" :name="'categories[' + i + '][caution]'" x-model="ligne.caution" type="number" min="1" class="champ" required>
                    </div>
                    <button type="button" class="bo-bouton bo-bouton-secondaire" @click="lignes.splice(i, 1)" x-show="lignes.length > 1">{{ $k['retirer'] }}</button>
                </fieldset>
            </template>
            <button type="button" class="bo-bouton bo-bouton-secondaire mt-4" @click="lignes.push({ libelle: '', description: '', nombre_boutiques: '', caution: '' })">{{ $k['ajouter'] }}</button>
        </section>

        <section class="bo-carte !mt-0" aria-labelledby="s-paiement">
            <h2 id="s-paiement" class="!text-[1.15rem]">{{ __('admin.boutiques.sections.paiement') }}</h2>
            <div class="mt-3 grid gap-4 lg:grid-cols-2">
                <x-admin.cases nom="canaux_actifs" :libelle="ucfirst($c['canaux_actifs'])"
                               :options="collect(CanalPaiement::cases())->mapWithKeys(fn ($e) => [$e->value => $e->label()])->all()"
                               :valeurs="$campagne->canaux_actifs ?? []" class="lg:col-span-2" />
                <x-admin.champ nom="delai_paiement_heures" :libelle="ucfirst($c['delai_paiement_heures'])" type="number" min="1" max="720" :valeur="$campagne->delai_paiement_heures" required />
            </div>
        </section>

        <div class="flex gap-3">
            <button type="submit" class="bo-bouton bo-bouton-principal">{{ __('admin.tranches.formulaire.enregistrer') }}</button>
            <a href="{{ $campagne->exists ? route('admin.boutiques.show', $campagne) : route('admin.boutiques') }}" class="bo-bouton bo-bouton-secondaire">{{ __('admin.tranches.formulaire.annuler') }}</a>
        </div>
    </form>
</x-admin.layout>
