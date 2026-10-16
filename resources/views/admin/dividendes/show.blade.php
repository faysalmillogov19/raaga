@php
    use App\Enums\Droit;
    use App\Enums\StatutDistribution;
    use App\Support\AffichageParametres;

    $d = $distribution;
    $libelles = __('admin.dividendes.champs');
    $libelles['taux_irvm_pb'] = $libelles['taux_irvm'];
    $libelles['pv_empreinte'] = 'empreinte du PV';
    $sections = [
        'decision' => ['classe_actions', 'exercice', 'ag_date', 'ag_pv_numero', 'pv_empreinte'],
        'calcul' => ['resultat_distribuable', 'mode_calcul', 'montant_global', 'dividende_brut_par_action'],
        'dates' => ['date_arrete', 'date_mise_en_paiement'],
        'versement' => ['taux_irvm_pb', 'frais_supportes_par', 'seuil_minimal', 'canaux_autorises', 'canal_defaut', 'tentatives', 'delai_tentatives_heures'],
        'cas' => ['conservation_non_reclames_mois', 'traitement_non_reclames'],
    ];
    $snapshot = $d->snapshotParametres();
    $titre = __('admin.dividendes.exercice', ['annee' => $d->exercice]);
@endphp

<x-admin.layout :titre="$titre" :fil="[['libelle' => __('admin.dividendes.titre'), 'url' => route('admin.dividendes')], ['libelle' => $titre]]">
    <section class="bo-carte !mt-0" aria-labelledby="etat-titre">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 id="etat-titre" class="!text-[1.15rem]">{{ __('admin.parametrage.statut') }} : <x-admin.badge-statut :statut="$d->statut" /></h2>
                <p class="texte-carte mt-1">{{ $d->version ? __('admin.parametrage.version', ['n' => $d->version]) : __('admin.parametrage.aucune_version') }}</p>
            </div>
            <div class="flex flex-wrap gap-3">
                @can(Droit::SimulationsLancer->value)
                    <x-admin.bouton variante="contour" href="{{ route('admin.dividendes.simulation', $d) }}">{{ __('admin.dividendes.actions.simuler') }}</x-admin.bouton>
                @endcan
                <x-admin.bouton variante="contour" href="{{ route('admin.dividendes.avis', $d) }}">{{ __('admin.dividendes.actions.avis') }}</x-admin.bouton>
                @if ($d->pv_chemin)
                    <x-admin.bouton variante="contour" href="{{ route('admin.dividendes.pv', $d) }}">{{ __('admin.dividendes.actions.pv') }}</x-admin.bouton>
                @endif
            </div>
        </div>

        @if ($d->motif_rejet && $d->statut === StatutDistribution::Brouillon)
            <x-admin.message type="erreur" class="mt-4 !mb-0">{{ __('admin.parametrage.motif_rejet', ['motif' => $d->motif_rejet]) }}</x-admin.message>
        @endif

        <div class="mt-4 flex flex-wrap gap-3">
            @can(Droit::DividendesSaisir->value)
                @if ($d->estModifiable())
                    <x-admin.bouton variante="contour" href="{{ route('admin.dividendes.edit', $d) }}">{{ __('admin.parametrage.actions.modifier') }}</x-admin.bouton>
                    <x-admin.confirmation etape="soumettre" id="soumettre" :titre="__('admin.parametrage.actions.soumettre')" :message="__('admin.parametrage.confirmations.soumettre')"
                                          :action="route('admin.dividendes.soumettre', $d)" :bouton="__('admin.parametrage.actions.soumettre')">
                        <x-slot:declencheur>{{ __('admin.parametrage.actions.soumettre') }}</x-slot:declencheur>
                        <label class="case-conditions" for="montant_conforme_pv">
                            <input id="montant_conforme_pv" name="montant_conforme_pv" type="checkbox" value="1" required>
                            <span><span class="adm-requis" aria-hidden="true">*</span> {{ __('admin.dividendes.soumission.attestation') }}</span>
                        </label>
                    </x-admin.confirmation>
                @elseif ($d->champsAvenantAutorises() !== [])
                    <x-admin.bouton variante="contour" href="{{ route('admin.dividendes.avenant', $d) }}">{{ __('admin.parametrage.actions.avenant') }}</x-admin.bouton>
                @endif
            @endcan

            @can(Droit::DividendesConfirmer->value)
                @if ($d->estSoumis())
                    <x-admin.confirmation etape="confirmer" id="confirmer" :titre="__('admin.parametrage.actions.confirmer')" :message="__('admin.parametrage.confirmations.confirmer')"
                                          :action="route('admin.dividendes.confirmer', $d)" :bouton="__('admin.parametrage.actions.confirmer')">
                        <x-slot:declencheur>{{ __('admin.parametrage.actions.confirmer') }}</x-slot:declencheur>
                    </x-admin.confirmation>
                    <x-admin.confirmation etape="rejeter" id="rejeter" :titre="__('admin.parametrage.actions.rejeter')" :message="__('admin.parametrage.confirmations.rejeter')"
                                          :action="route('admin.dividendes.rejeter', $d)" :bouton="__('admin.parametrage.actions.rejeter')" motif danger>
                        <x-slot:declencheur>{{ __('admin.parametrage.actions.rejeter') }}</x-slot:declencheur>
                    </x-admin.confirmation>
                @endif
            @endcan
        </div>

        @can(Droit::DividendesPayer->value)
            @if ($d->statut === StatutDistribution::Confirme)
                <p class="texte-carte mt-4"><strong>{{ __('admin.dividendes.actions.paiement') }}</strong> · {{ __('admin.dividendes.actions.paiement_bientot') }}</p>
            @endif
        @endcan
        @error('motif') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
        @error('montant_conforme_pv') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
    </section>

    @if ($d->estSoumis())
        <section class="bo-carte" aria-labelledby="diff-titre">
            <h2 id="diff-titre" class="!text-[1.15rem]">{{ __('admin.parametrage.differentiel') }}</h2>
            <div class="mt-2"><x-admin.differentiel :differentiel="$differentiel" :libelles="$libelles" :premiere="$premiereVersion" /></div>
        </section>
    @endif

    <div class="my-[18px] grid gap-[18px] xl:grid-cols-2">
        @foreach ($sections as $section => $champs)
            <section class="bo-carte !mt-0" aria-labelledby="sec-{{ $section }}">
                <h2 id="sec-{{ $section }}" class="!text-[1.05rem]">{{ __('admin.dividendes.sections.'.$section) }}</h2>
                <dl class="bo-definitions mt-3">
                    @foreach ($champs as $champ)
                        <dt>{{ ucfirst($libelles[$champ]) }}</dt>
                        <dd>{{ AffichageParametres::valeur($champ, $snapshot[$champ]) }}</dd>
                    @endforeach
                </dl>
            </section>
        @endforeach
    </div>

    @include('admin.parametrage._avenants', [
        'avenants' => $avenants, 'libelles' => $libelles, 'objet' => $d,
        'droitConfirmer' => Droit::DividendesConfirmer->value,
        'routeConfirmer' => 'admin.dividendes.avenant.confirmer', 'routeRejeter' => 'admin.dividendes.avenant.rejeter',
    ])

    @include('admin.parametrage._historique', ['versions' => $versions, 'decisions' => $decisions])
</x-admin.layout>
