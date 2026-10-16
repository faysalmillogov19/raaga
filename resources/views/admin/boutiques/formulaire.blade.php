@php
    use App\Models\MoyenPaiement;

    $c = __('admin.boutiques.champs');
    $titre = __('admin.boutiques.formulaire.'.$mode);
    $dateHeure = fn ($d) => $d ? \Carbon\Carbon::parse($d)->format('Y-m-d\TH:i') : null;
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

        {{-- Sites concernés : la campagne propose toutes leurs boutiques réservables, au premier arrivé ; montants issus de la grille tarifaire. --}}
        <section class="bo-carte !mt-0" aria-labelledby="s-sites">
            <h2 id="s-sites" class="!text-[1.15rem]">{{ __('admin.boutiques.sections.sites') }}</h2>
            <p class="texte-carte mt-1">{{ __('admin.boutiques.aides.sites') }}</p>
            @error('sites') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
            @if ($sitesDisponibles->isEmpty())
                <x-admin.message type="erreur" class="mt-4">{{ __('admin.boutiques.aucun_site') }} <a href="{{ route('admin.sites') }}">{{ __('admin.sites.titre') }}</a></x-admin.message>
            @else
                <x-admin.cases nom="sites" :libelle="ucfirst($c['sites'])" class="mt-3"
                               :options="$sitesDisponibles->mapWithKeys(fn ($site) => [$site->id => ($site->code ? $site->code.' · ' : '').$site->nom])->all()"
                               :valeurs="array_map('strval', (array) ($campagne->sites ?? []))" />
            @endif

            {{-- Types proposés : actifs, validés et attribuables ; la chambre froide mutualisée (CF) n'est jamais proposée. --}}
            <h3 class="mt-6 !text-base" id="s-types">{{ __('admin.boutiques.sections.types') }}</h3>
            <p class="texte-carte mt-1">{{ __('admin.boutiques.aides.types') }}</p>
            @error('types') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
            @error('types.*') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
            <x-admin.cases nom="types" :libelle="ucfirst($c['types'])" class="mt-3" data-types-campagne
                           :options="$typesDisponibles->mapWithKeys(fn ($t) => [$t->id => ($t->code ? $t->code.' · ' : '').$t->libelle])->all()"
                           :valeurs="array_map('strval', (array) ($campagne->types ?? []))" />
        </section>

        <section class="bo-carte !mt-0" aria-labelledby="s-paiement">
            <h2 id="s-paiement" class="!text-[1.15rem]">{{ __('admin.boutiques.sections.paiement') }}</h2>
            <div class="mt-3 grid gap-4 lg:grid-cols-2">
                <x-admin.cases nom="canaux_actifs" :libelle="ucfirst($c['canaux_actifs'])"
                               :options="MoyenPaiement::proposables()->pluck('libelle', 'code')->all()"
                               :valeurs="$campagne->canaux_actifs ?? []" class="lg:col-span-2" />
                {{-- Délai de paiement de la caution : celui des « Délais de paiement » (RÉGULATEUR), sauf ancien délai propre à la campagne. --}}
                <div class="formulaire-champ adm-champ !mt-0" data-delai-caution>
                    <p class="adm-libelle">{{ ucfirst($c['delai_paiement']) }}</p>
                    <p class="font-semibold">{{ $campagne->libelleDelaiPaiement() }}</p>
                    <p class="texte-carte">{{ __($campagne->aSonPropreDelai() ? 'admin.boutiques.aides.delai_propre' : 'admin.boutiques.aides.delai_paiement') }}</p>
                </div>
                <x-admin.champ nom="max_boutiques_par_personne" :libelle="ucfirst($c['max_boutiques_par_personne'])" type="number" min="1" required
                               :valeur="$campagne->max_boutiques_par_personne" :aide="__('admin.boutiques.aides.max_boutiques_par_personne')" />
            </div>
        </section>

        <div class="flex flex-wrap gap-3">
            <x-admin.enregistrer-soumettre />
            <x-admin.bouton variante="contour" href="{{ $campagne->exists ? route('admin.boutiques.show', $campagne) : route('admin.boutiques') }}">{{ __('admin.tranches.formulaire.annuler') }}</x-admin.bouton>
        </div>
    </form>
</x-admin.layout>
