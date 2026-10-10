@php
    use App\Enums\Droit;
    use App\Enums\StatutTranche;
    use App\Support\AffichageParametres;

    $libelles = __('admin.tranches.champs');
    $sections = [
        'offre' => ['nom', 'valeur_unitaire', 'min_actions_par_souscription', 'plafond_actions_par_personne', 'objectif_montant', 'date_ouverture', 'date_cloture', 'publics_autorises'],
        'saisie' => ['mode_saisie', 'souscriptions_multiples'],
        'sursouscription' => ['regle_arret', 'pourcentage_depassement_max', 'methode_allocation', 'seuil_garanti_petits', 'plafond_concentration_pct'],
        'echec' => ['seuil_reussite_montant', 'regle_si_echec', 'jours_prolongation', 'delai_remboursement_jours'],
        'frais' => ['frais_paiement_supportes_par', 'frais_souscription_type', 'frais_souscription_valeur'],
        'paiement' => ['canaux_actifs', 'delai_paiement_heures', 'seuil_kyc_renforce', 'seuil_revue_conformite'],
    ];
    $snapshot = $tranche->snapshotParametres();
@endphp

<x-admin.layout :titre="$tranche->nom" :fil="[['libelle' => __('admin.tranches.titre'), 'url' => route('admin.tranches')], ['libelle' => $tranche->nom]]">
    {{-- Statut et actions --}}
    <section class="bo-carte !mt-0" aria-labelledby="etat-titre">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 id="etat-titre" class="!text-[1.15rem]">{{ __('admin.parametrage.statut') }} : @include('admin.parametrage._statut', ['statut' => $tranche->statut])</h2>
                <p class="texte-carte mt-1">{{ $tranche->version ? __('admin.parametrage.version', ['n' => $tranche->version]) : __('admin.parametrage.aucune_version') }}</p>
            </div>
            <a href="{{ route('admin.tranches.reglement', $tranche) }}" class="bo-bouton bo-bouton-secondaire">{{ __('admin.tranches.actions.reglement') }}</a>
        </div>

        @if ($tranche->motif_rejet && $tranche->statut === StatutTranche::Brouillon)
            <p class="bo-message bo-message-erreur mt-4 !mb-0" role="alert">{{ __('admin.parametrage.motif_rejet', ['motif' => $tranche->motif_rejet]) }}</p>
        @endif

        <div class="mt-4 flex flex-wrap gap-3">
            {{-- RÉGULATEUR : saisie, soumission, avenant --}}
            @can(Droit::TranchesSaisir->value)
                @if ($tranche->estModifiable())
                    <a href="{{ route('admin.tranches.edit', $tranche) }}" class="bo-bouton bo-bouton-secondaire">{{ __('admin.parametrage.actions.modifier') }}</a>
                    <x-admin.confirmation id="soumettre" :titre="__('admin.parametrage.actions.soumettre')" :message="__('admin.parametrage.confirmations.soumettre')"
                                          :action="route('admin.tranches.soumettre', $tranche)" :bouton="__('admin.parametrage.actions.soumettre')">
                        <x-slot:declencheur>{{ __('admin.parametrage.actions.soumettre') }}</x-slot:declencheur>
                    </x-admin.confirmation>
                @elseif ($tranche->champsAvenantAutorises() !== [])
                    <a href="{{ route('admin.tranches.avenant', $tranche) }}" class="bo-bouton bo-bouton-secondaire">
                        {{ $tranche->statut === StatutTranche::Ouverte ? __('admin.tranches.actions.prolonger') : __('admin.parametrage.actions.avenant') }}
                    </a>
                @endif
            @endcan

            {{-- VALIDATEUR : confirmation, rejet, ouverture, clôture --}}
            @can(Droit::TranchesConfirmer->value)
                @if ($tranche->estSoumis())
                    <x-admin.confirmation id="confirmer" :titre="__('admin.parametrage.actions.confirmer')" :message="__('admin.parametrage.confirmations.confirmer')"
                                          :action="route('admin.tranches.confirmer', $tranche)" :bouton="__('admin.parametrage.actions.confirmer')">
                        <x-slot:declencheur>{{ __('admin.parametrage.actions.confirmer') }}</x-slot:declencheur>
                    </x-admin.confirmation>
                    <x-admin.confirmation id="rejeter" :titre="__('admin.parametrage.actions.rejeter')" :message="__('admin.parametrage.confirmations.rejeter')"
                                          :action="route('admin.tranches.rejeter', $tranche)" :bouton="__('admin.parametrage.actions.rejeter')" motif danger>
                        <x-slot:declencheur>{{ __('admin.parametrage.actions.rejeter') }}</x-slot:declencheur>
                    </x-admin.confirmation>
                @endif
            @endcan
            @can(Droit::TranchesPiloter->value)
                @if ($tranche->statut === StatutTranche::Confirmee)
                    <x-admin.confirmation-renforcee id="ouvrir" :titre="__('admin.tranches.actions.ouvrir')" :message="__('admin.tranches.confirmations.ouvrir')"
                                                    :action="route('admin.tranches.ouvrir', $tranche)" :bouton="__('admin.tranches.actions.ouvrir')" :totp="$totpExige" />
                @elseif ($tranche->statut === StatutTranche::Ouverte)
                    <x-admin.confirmation-renforcee id="cloturer" :titre="__('admin.tranches.actions.cloturer')" :message="__('admin.tranches.confirmations.cloturer')"
                                                    :action="route('admin.tranches.cloturer', $tranche)" :bouton="__('admin.tranches.actions.cloturer')" :totp="$totpExige" />
                @endif
            @endcan
        </div>
        @error('motif') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
    </section>

    {{-- Différentiel de la version soumise (revue du validateur) --}}
    @if ($tranche->estSoumis())
        <section class="bo-carte" aria-labelledby="diff-titre">
            <h2 id="diff-titre" class="!text-[1.15rem]">{{ __('admin.parametrage.differentiel') }}</h2>
            <div class="mt-2"><x-admin.differentiel :differentiel="$differentiel" :libelles="$libelles" :premiere="$premiereVersion" /></div>
        </section>
    @endif

    {{-- Paramètres --}}
    <div class="my-[18px] grid gap-[18px] xl:grid-cols-2">
        @foreach ($sections as $section => $champs)
            <section class="bo-carte !mt-0" aria-labelledby="sec-{{ $section }}">
                <h2 id="sec-{{ $section }}" class="!text-[1.05rem]">{{ __('admin.tranches.sections.'.$section) }}</h2>
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
        'avenants' => $avenants, 'libelles' => $libelles, 'objet' => $tranche,
        'droitConfirmer' => Droit::TranchesConfirmer->value,
        'routeConfirmer' => 'admin.tranches.avenant.confirmer', 'routeRejeter' => 'admin.tranches.avenant.rejeter',
    ])

    @include('admin.parametrage._historique', ['versions' => $versions, 'decisions' => $decisions])
</x-admin.layout>
