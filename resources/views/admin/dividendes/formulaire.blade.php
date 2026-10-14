@php
    use App\Enums\ModeCalculDividende;
    use App\Enums\TraitementNonReclames;
    use App\Http\Requests\Admin\DistributionParametresRequest;

    $d = $distribution;
    $c = __('admin.dividendes.champs');
    $a = __('admin.dividendes.aides');
    $options = fn (string $enum) => collect($enum::cases())->mapWithKeys(fn ($e) => [$e->value => $e->label()])->all();
    $canaux = collect(DistributionParametresRequest::CANAUX)->mapWithKeys(fn ($e) => [$e->value => $e->label()])->all();
    $date = fn ($v) => $v ? \Carbon\Carbon::parse($v)->format('Y-m-d') : null;
    $titre = __('admin.dividendes.formulaire.'.$mode);
    $action = match ($mode) {
        'creation' => route('admin.dividendes.store'),
        'modification' => route('admin.dividendes.update', $d),
        'avenant' => route('admin.dividendes.avenant.store', $d),
    };
    $fil = [['libelle' => __('admin.dividendes.titre'), 'url' => route('admin.dividendes')]];
    if ($d->exists) {
        $fil[] = ['libelle' => __('admin.dividendes.exercice', ['annee' => $d->exercice]), 'url' => route('admin.dividendes.show', $d)];
    }
    $fil[] = ['libelle' => $titre];
@endphp

<x-admin.layout :titre="$titre" :fil="$fil">
    <form method="POST" action="{{ $action }}" enctype="multipart/form-data" class="space-y-[18px]" novalidate>
        @csrf
        @if ($mode === 'modification')
            @method('PUT')
        @endif

        @if ($mode === 'avenant')
            <div class="bo-carte !mt-0">
                <p>{{ __('admin.dividendes.formulaire.avenant_intro') }}</p>
                <div class="formulaire-champ mt-4">
                    <label for="motif">{{ __('admin.parametrage.actions.motif') }}</label>
                    <textarea id="motif" name="motif" rows="2" required class="champ @error('motif') est-invalide @enderror">{{ old('motif') }}</textarea>
                    @error('motif') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
                </div>
            </div>
        @endif

        <section class="bo-carte !mt-0" aria-labelledby="s-decision">
            <h2 id="s-decision" class="!text-[1.15rem]">{{ __('admin.dividendes.sections.decision') }}</h2>
            <div class="mt-3 grid gap-4 lg:grid-cols-2">
                <x-admin.liste nom="classe_actions" :libelle="ucfirst($c['classe_actions'])" :options="$options(\App\Enums\ClasseActions::class)" :valeur="$d->classe_actions" />
                <x-admin.champ nom="exercice" :libelle="ucfirst($c['exercice'])" type="number" min="2000" max="2100" :valeur="$d->exercice" required />
                <x-admin.champ nom="ag_date" :libelle="ucfirst($c['ag_date'])" type="date" :valeur="$date($d->ag_date)" required />
                <x-admin.champ nom="ag_pv_numero" :libelle="ucfirst($c['ag_pv_numero'])" :valeur="$d->ag_pv_numero" required maxlength="60" />
                @if ($mode !== 'avenant')
                    <x-admin.depot-fichier nom="pv" id="pv" :libelle="ucfirst($c['pv'])" formats=".pdf" accept="application/pdf"
                                           :aide="($d->pv_nom_original ? __('admin.dividendes.formulaire.pv_actuel', ['nom' => $d->pv_nom_original]).' · ' : '').__('admin.dividendes.formulaire.pv_aide')" />
                @endif
            </div>
        </section>

        <section class="bo-carte" aria-labelledby="s-calcul">
            <h2 id="s-calcul" class="!text-[1.15rem]">{{ __('admin.dividendes.sections.calcul') }}</h2>
            <div class="mt-3 grid gap-4 lg:grid-cols-2">
                <x-admin.champ nom="resultat_distribuable" :libelle="ucfirst($c['resultat_distribuable'])" type="number" min="0" :valeur="$d->resultat_distribuable" :aide="$a['resultat']" required />
                <x-admin.liste nom="mode_calcul" :libelle="ucfirst($c['mode_calcul'])" :options="$options(ModeCalculDividende::class)" :valeur="$d->mode_calcul" />
                <x-admin.champ nom="montant_global" :libelle="ucfirst($c['montant_global'])" type="number" min="1" :valeur="$d->montant_global" />
                <x-admin.champ nom="dividende_brut_par_action" :libelle="ucfirst($c['dividende_brut_par_action'])" type="number" min="1" :valeur="$d->dividende_brut_par_action" />
            </div>
        </section>

        <section class="bo-carte" aria-labelledby="s-dates">
            <h2 id="s-dates" class="!text-[1.15rem]">{{ __('admin.dividendes.sections.dates') }}</h2>
            <div class="mt-3 grid gap-4 lg:grid-cols-2">
                <x-admin.champ nom="date_arrete" :libelle="ucfirst($c['date_arrete'])" type="date" :valeur="$date($d->date_arrete)" :aide="$a['arrete']" required />
                <x-admin.champ nom="date_mise_en_paiement" :libelle="ucfirst($c['date_mise_en_paiement'])" type="date" :valeur="$date($d->date_mise_en_paiement)" required />
            </div>
        </section>

        <section class="bo-carte" aria-labelledby="s-versement">
            <h2 id="s-versement" class="!text-[1.15rem]">{{ __('admin.dividendes.sections.versement') }}</h2>
            <div class="mt-3 grid gap-4 lg:grid-cols-2">
                <x-admin.champ nom="taux_irvm" :libelle="ucfirst($c['taux_irvm'])" type="number" min="0" max="100" step="0.01" :valeur="$d->taux_irvm_pb !== null ? $d->taux_irvm_pb / 100 : null" :aide="__('admin.dividendes.formulaire.taux_aide')" required />
                <x-admin.liste nom="frais_supportes_par" :libelle="ucfirst($c['frais_supportes_par'])" :options="__('admin.dividendes.payeurs')" :valeur="$d->frais_supportes_par" />
                <x-admin.champ nom="seuil_minimal" :libelle="ucfirst($c['seuil_minimal'])" type="number" min="0" :valeur="$d->seuil_minimal" :aide="$a['seuil']" required />
                <x-admin.liste nom="canal_defaut" :libelle="ucfirst($c['canal_defaut'])" :options="$canaux" :valeur="$d->canal_defaut" />
                <x-admin.cases nom="canaux_autorises" :libelle="ucfirst($c['canaux_autorises'])" :options="$canaux" :valeurs="$d->canaux_autorises ?? []" class="lg:col-span-2" />
                <x-admin.champ nom="tentatives" :libelle="ucfirst($c['tentatives'])" type="number" min="0" max="10" :valeur="$d->tentatives" required />
                <x-admin.champ nom="delai_tentatives_heures" :libelle="ucfirst($c['delai_tentatives_heures'])" type="number" min="1" max="720" :valeur="$d->delai_tentatives_heures" required />
            </div>
        </section>

        <section class="bo-carte" aria-labelledby="s-cas">
            <h2 id="s-cas" class="!text-[1.15rem]">{{ __('admin.dividendes.sections.cas') }}</h2>
            <div class="mt-3 grid gap-4 lg:grid-cols-2">
                <x-admin.champ nom="conservation_non_reclames_mois" :libelle="ucfirst($c['conservation_non_reclames_mois'])" type="number" min="1" max="600" :valeur="$d->conservation_non_reclames_mois" :aide="$a['conservation']" required />
                <x-admin.liste nom="traitement_non_reclames" :libelle="ucfirst($c['traitement_non_reclames'])" :options="$options(TraitementNonReclames::class)" :valeur="$d->traitement_non_reclames" />
            </div>
            <p class="texte-carte mt-3">{{ $a['bloquees'] }}</p>
        </section>

        <div class="flex gap-3">
            <x-admin.bouton variante="principal" type="submit">{{ $mode === 'avenant' ? __('admin.dividendes.formulaire.soumettre_avenant') : __('admin.dividendes.formulaire.enregistrer') }}</x-admin.bouton>
            <x-admin.bouton variante="contour" href="{{ $d->exists ? route('admin.dividendes.show', $d) : route('admin.dividendes') }}">{{ __('admin.dividendes.formulaire.annuler') }}</x-admin.bouton>
        </div>
    </form>
</x-admin.layout>
