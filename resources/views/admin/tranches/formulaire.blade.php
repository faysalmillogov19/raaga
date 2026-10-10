@php
    use App\Enums\CanalPaiement;
    use App\Enums\MethodeAllocation;
    use App\Enums\ModeSaisie;
    use App\Enums\PayeurFrais;
    use App\Enums\PublicAutorise;
    use App\Enums\RegleArret;
    use App\Enums\RegleSiEchec;
    use App\Enums\TypeFraisSouscription;

    $t = $tranche;
    $c = __('admin.tranches.champs');
    $options = fn (string $enum) => collect($enum::cases())->mapWithKeys(fn ($e) => [$e->value => $e->label()])->all();
    $dateHeure = fn ($d) => $d ? \Carbon\Carbon::parse($d)->format('Y-m-d\TH:i') : null;
    $clotureSeule = $mode === 'avenant' && $t->champsAvenantAutorises() === ['date_cloture'];
    $titre = __('admin.tranches.formulaire.'.$mode);
    $action = match ($mode) {
        'creation' => route('admin.tranches.store'),
        'modification' => route('admin.tranches.update', $t),
        'avenant' => route('admin.tranches.avenant.store', $t),
    };
    $fil = [['libelle' => __('admin.tranches.titre'), 'url' => route('admin.tranches')]];
    if ($t->exists) {
        $fil[] = ['libelle' => $t->nom, 'url' => route('admin.tranches.show', $t)];
    }
    $fil[] = ['libelle' => $titre];
@endphp

<x-admin.layout :titre="$titre" :fil="$fil">
    <form method="POST" action="{{ $action }}" class="space-y-[18px]" novalidate>
        @csrf
        @if ($mode === 'modification')
            @method('PUT')
        @endif

        @if ($mode === 'avenant')
            <div class="bo-carte !mt-0">
                <p>{{ $clotureSeule ? __('admin.tranches.formulaire.avenant_cloture') : __('admin.tranches.formulaire.avenant_intro') }}</p>
                <div class="formulaire-champ mt-4">
                    <label for="motif">{{ __('admin.parametrage.actions.motif') }}</label>
                    <textarea id="motif" name="motif" rows="2" required class="champ @error('motif') est-invalide @enderror">{{ old('motif') }}</textarea>
                    @error('motif') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
                </div>
            </div>
        @endif

        @if ($clotureSeule)
            <section class="bo-carte !mt-0">
                <x-admin.champ nom="date_cloture" :libelle="ucfirst($c['date_cloture'])" type="datetime-local" :valeur="$dateHeure($t->date_cloture)" required />
            </section>
        @else
            {{-- Offre --}}
            <section class="bo-carte !mt-0" aria-labelledby="s-offre">
                <h2 id="s-offre" class="!text-[1.15rem]">{{ __('admin.tranches.sections.offre') }}</h2>
                <div class="mt-3 grid gap-4 lg:grid-cols-2">
                    <x-admin.champ nom="nom" :libelle="ucfirst($c['nom'])" :valeur="$t->nom" required maxlength="150" class="lg:col-span-2" />
                    <x-admin.champ nom="valeur_unitaire" :libelle="ucfirst($c['valeur_unitaire'])" type="number" min="1" :valeur="$t->valeur_unitaire" required />
                    <x-admin.champ nom="objectif_montant" :libelle="ucfirst($c['objectif_montant'])" type="number" min="1" :valeur="$t->objectif_montant" required />
                    <x-admin.champ nom="min_actions_par_souscription" :libelle="ucfirst($c['min_actions_par_souscription'])" type="number" min="1" :valeur="$t->min_actions_par_souscription" required />
                    <x-admin.champ nom="plafond_actions_par_personne" :libelle="ucfirst($c['plafond_actions_par_personne'])" type="number" min="1" :valeur="$t->plafond_actions_par_personne" :aide="__('admin.tranches.sans_plafond').' : laisser vide.'" />
                    <x-admin.champ nom="date_ouverture" :libelle="ucfirst($c['date_ouverture'])" type="datetime-local" :valeur="$dateHeure($t->date_ouverture)" required />
                    <x-admin.champ nom="date_cloture" :libelle="ucfirst($c['date_cloture'])" type="datetime-local" :valeur="$dateHeure($t->date_cloture)" required />
                    <x-admin.cases nom="publics_autorises" :libelle="ucfirst($c['publics_autorises'])" :options="$options(PublicAutorise::class)" :valeurs="$t->publics_autorises ?? []" class="lg:col-span-2" />
                </div>
            </section>

            {{-- Saisie --}}
            <section class="bo-carte" aria-labelledby="s-saisie">
                <h2 id="s-saisie" class="!text-[1.15rem]">{{ __('admin.tranches.sections.saisie') }}</h2>
                <div class="mt-3 grid gap-4 lg:grid-cols-2">
                    <x-admin.liste nom="mode_saisie" :libelle="ucfirst($c['mode_saisie'])" :options="$options(ModeSaisie::class)" :valeur="$t->mode_saisie" />
                    <div class="formulaire-champ !mt-0">
                        <input type="hidden" name="souscriptions_multiples" value="0">
                        <label class="inline-flex min-h-[44px] items-center gap-2 font-semibold">
                            <input type="checkbox" name="souscriptions_multiples" value="1" @checked(old('souscriptions_multiples', $t->souscriptions_multiples)) class="h-5 w-5 rounded border-2 border-gris text-vert">
                            {{ ucfirst($c['souscriptions_multiples']) }}
                        </label>
                    </div>
                </div>
            </section>

            {{-- Sursouscription --}}
            <section class="bo-carte" aria-labelledby="s-sur">
                <h2 id="s-sur" class="!text-[1.15rem]">{{ __('admin.tranches.sections.sursouscription') }}</h2>
                <div class="mt-3 grid gap-4 lg:grid-cols-2">
                    <x-admin.liste nom="regle_arret" :libelle="ucfirst($c['regle_arret'])" :options="$options(RegleArret::class)" :valeur="$t->regle_arret" />
                    <x-admin.champ nom="pourcentage_depassement_max" :libelle="ucfirst($c['pourcentage_depassement_max'])" type="number" min="1" max="100" :valeur="$t->pourcentage_depassement_max" />
                    <x-admin.liste nom="methode_allocation" :libelle="ucfirst($c['methode_allocation'])" :options="$options(MethodeAllocation::class)" :valeur="$t->methode_allocation" />
                    <x-admin.champ nom="seuil_garanti_petits" :libelle="ucfirst($c['seuil_garanti_petits'])" type="number" min="1" :valeur="$t->seuil_garanti_petits" />
                    <x-admin.champ nom="plafond_concentration_pct" :libelle="ucfirst($c['plafond_concentration_pct'])" type="number" min="1" max="100" :valeur="$t->plafond_concentration_pct" />
                </div>
            </section>

            {{-- Tranche non atteinte --}}
            <section class="bo-carte" aria-labelledby="s-echec">
                <h2 id="s-echec" class="!text-[1.15rem]">{{ __('admin.tranches.sections.echec') }}</h2>
                <div class="mt-3 grid gap-4 lg:grid-cols-2">
                    <x-admin.champ nom="seuil_reussite_montant" :libelle="ucfirst($c['seuil_reussite_montant'])" type="number" min="0" :valeur="$t->seuil_reussite_montant" />
                    <x-admin.liste nom="regle_si_echec" :libelle="ucfirst($c['regle_si_echec'])" :options="$options(RegleSiEchec::class)" :valeur="$t->regle_si_echec" />
                    <x-admin.champ nom="jours_prolongation" :libelle="ucfirst($c['jours_prolongation'])" type="number" min="1" max="365" :valeur="$t->jours_prolongation" />
                    <x-admin.champ nom="delai_remboursement_jours" :libelle="ucfirst($c['delai_remboursement_jours'])" type="number" min="1" max="365" :valeur="$t->delai_remboursement_jours" required />
                </div>
            </section>

            {{-- Frais --}}
            <section class="bo-carte" aria-labelledby="s-frais">
                <h2 id="s-frais" class="!text-[1.15rem]">{{ __('admin.tranches.sections.frais') }}</h2>
                <div class="mt-3 grid gap-4 lg:grid-cols-2">
                    <x-admin.liste nom="frais_paiement_supportes_par" :libelle="ucfirst($c['frais_paiement_supportes_par'])" :options="$options(PayeurFrais::class)" :valeur="$t->frais_paiement_supportes_par" />
                    <x-admin.liste nom="frais_souscription_type" :libelle="ucfirst($c['frais_souscription_type'])" :options="$options(TypeFraisSouscription::class)" :valeur="$t->frais_souscription_type" />
                    <x-admin.champ nom="frais_souscription_valeur" :libelle="ucfirst($c['frais_souscription_valeur'])" type="number" min="0" :valeur="$t->frais_souscription_valeur" :aide="__('admin.tranches.formulaire.aide_pb')" />
                </div>
            </section>

            {{-- Paiement & conformité --}}
            <section class="bo-carte" aria-labelledby="s-paiement">
                <h2 id="s-paiement" class="!text-[1.15rem]">{{ __('admin.tranches.sections.paiement') }}</h2>
                <div class="mt-3 grid gap-4 lg:grid-cols-2">
                    <x-admin.cases nom="canaux_actifs" :libelle="ucfirst($c['canaux_actifs'])" :options="$options(CanalPaiement::class)" :valeurs="$t->canaux_actifs ?? []" class="lg:col-span-2" />
                    <x-admin.champ nom="delai_paiement_heures" :libelle="ucfirst($c['delai_paiement_heures'])" type="number" min="1" max="720" :valeur="$t->delai_paiement_heures" required />
                    <x-admin.champ nom="seuil_kyc_renforce" :libelle="ucfirst($c['seuil_kyc_renforce'])" type="number" min="0" :valeur="$t->seuil_kyc_renforce" />
                    <x-admin.champ nom="seuil_revue_conformite" :libelle="ucfirst($c['seuil_revue_conformite'])" type="number" min="0" :valeur="$t->seuil_revue_conformite" />
                </div>
            </section>
        @endif

        <div class="flex gap-3">
            <button type="submit" class="bo-bouton bo-bouton-principal">{{ $mode === 'avenant' ? __('admin.tranches.formulaire.soumettre_avenant') : __('admin.tranches.formulaire.enregistrer') }}</button>
            <a href="{{ $t->exists ? route('admin.tranches.show', $t) : route('admin.tranches') }}" class="bo-bouton bo-bouton-secondaire">{{ __('admin.tranches.formulaire.annuler') }}</a>
        </div>
    </form>
</x-admin.layout>
