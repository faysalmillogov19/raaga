{{--
    Paramètres d'investissement RAAGA Invest : objectif de collecte (peut être dépassé), valeur indicative par action,
    plafond global facultatif. RÉGULATEUR : saisie (fenêtre) et soumission ; VALIDATEUR : validation ou rejet. Le site
    public lit la dernière version confirmée, même pendant une révision.
--}}
@php
    use App\Enums\Droit;
    use App\Enums\StatutGrilleTarifaire;

    $i = __('admin.investissement');
    $libelles = $i['champs'];
    $saisir = auth()->user()->can(Droit::TranchesSaisir->value);
    $modifiable = $saisir && (! $fiche->exists || $fiche->estModifiable());
    $montant = fn (?int $v) => $v === null ? $i['sans_plafond'] : format_fcfa($v);
@endphp

<x-admin.layout :titre="$i['titre']" :sous-titre="$i['intro']" :fil="[['libelle' => $i['titre']]]">
    <section class="bo-carte !mt-0" aria-labelledby="etat-titre">
        <h2 id="etat-titre" class="!text-[1.15rem]">{{ __('admin.parametrage.statut') }} :
            @if ($fiche->exists)<x-admin.badge-statut :statut="$fiche->statut" />@else{{ $i['aucun'] }}@endif</h2>
        @if ($fiche->motif_rejet && $fiche->statut === StatutGrilleTarifaire::Brouillon)
            <x-admin.message type="erreur" class="mt-4 !mb-0">{{ __('admin.parametrage.motif_rejet', ['motif' => $fiche->motif_rejet]) }}</x-admin.message>
        @endif

        @if ($fiche->exists)
            <dl class="mt-4 grid gap-3 sm:grid-cols-2" data-saisie>
                <div><dt class="texte-carte">{{ ucfirst($libelles['objectif_collecte']) }}</dt><dd class="font-bold">{{ $montant($fiche->objectif_collecte) }}</dd></div>
                <div><dt class="texte-carte">{{ ucfirst($libelles['valeur_action']) }}</dt><dd class="font-bold">{{ $montant($fiche->valeur_action) }}</dd></div>
                <div><dt class="texte-carte">{{ ucfirst($libelles['minimum_actions']) }}</dt><dd class="font-bold">{{ format_nombre($fiche->minimum_actions ?? 1) }}</dd></div>
                <div><dt class="texte-carte">{{ ucfirst($libelles['plafond_global']) }}</dt><dd class="font-bold">{{ $montant($fiche->plafond_global) }}</dd></div>
            </dl>
        @endif
        <p class="texte-carte mt-3" data-confirmes>{{ $confirmes
            ? __('admin.investissement.en_vigueur', ['objectif' => format_fcfa($confirmes['objectif_collecte']), 'valeur' => format_fcfa($confirmes['valeur_action']), 'minimum' => format_nombre($confirmes['minimum_actions'])])
            : $i['aucune_confirmee'] }}</p>

        <div class="mt-4 flex flex-wrap gap-3">
            @if ($modifiable)
                <x-admin.fenetre id="modifier-investissement" :titre="$i['titre']" :action="route('admin.investissement.update')" methode="PUT"
                                 :bouton="__('admin.composants.circuit.brouillon')" declencheur-variante="secondaire" soumission>
                    <x-slot:declencheur>{{ __('admin.parametrage.actions.modifier') }}</x-slot:declencheur>
                    <x-admin.champ nom="objectif_collecte" :libelle="ucfirst($libelles['objectif_collecte'])" type="number" min="1" :valeur="$fiche->objectif_collecte" obligatoire :aide="$i['aides']['objectif_collecte']" />
                    <x-admin.champ nom="valeur_action" :libelle="ucfirst($libelles['valeur_action'])" type="number" min="1" :valeur="$fiche->valeur_action" obligatoire class="mt-4" />
                    <x-admin.champ nom="minimum_actions" :libelle="ucfirst($libelles['minimum_actions'])" type="number" min="1" :valeur="$fiche->minimum_actions ?? 1" obligatoire class="mt-4" :aide="$i['aides']['minimum_actions']" />
                    <x-admin.champ nom="plafond_global" :libelle="ucfirst($libelles['plafond_global'])" type="number" min="1" :valeur="$fiche->plafond_global" class="mt-4" :aide="$i['aides']['plafond_global']" />
                </x-admin.fenetre>
            @endif
            @if ($modifiable && $fiche->exists)
                <x-admin.confirmation etape="soumettre" id="soumettre" :titre="__('admin.parametrage.actions.soumettre')" :message="__('admin.parametrage.confirmations.soumettre')"
                                      :action="route('admin.investissement.soumettre')" :bouton="__('admin.parametrage.actions.soumettre')">
                    <x-slot:declencheur>{{ __('admin.parametrage.actions.soumettre') }}</x-slot:declencheur>
                </x-admin.confirmation>
            @elseif ($saisir && $fiche->statut === StatutGrilleTarifaire::Confirme)
                <x-admin.confirmation etape="reviser" id="reviser" :titre="__('admin.grilles.actions.reviser')" :message="__('admin.grilles.confirmations.reviser')"
                                      :action="route('admin.investissement.reviser')" :bouton="__('admin.grilles.actions.reviser')">
                    <x-slot:declencheur>{{ __('admin.grilles.actions.reviser') }}</x-slot:declencheur>
                </x-admin.confirmation>
            @endif

            @can(Droit::TranchesConfirmer->value)
                @if ($fiche->estSoumis())
                    <x-admin.confirmation etape="confirmer" id="confirmer" :titre="__('admin.parametrage.actions.confirmer')" :message="__('admin.parametrage.confirmations.confirmer')"
                                          :action="route('admin.investissement.confirmer')" :bouton="__('admin.parametrage.actions.confirmer')">
                        <x-slot:declencheur>{{ __('admin.parametrage.actions.confirmer') }}</x-slot:declencheur>
                    </x-admin.confirmation>
                    <x-admin.confirmation etape="rejeter" id="rejeter" :titre="__('admin.parametrage.actions.rejeter')" :message="__('admin.parametrage.confirmations.rejeter')"
                                          :action="route('admin.investissement.rejeter')" :bouton="__('admin.parametrage.actions.rejeter')" motif danger>
                        <x-slot:declencheur>{{ __('admin.parametrage.actions.rejeter') }}</x-slot:declencheur>
                    </x-admin.confirmation>
                @endif
            @endcan
        </div>
        @error('motif') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
    </section>

    @if ($fiche->estSoumis())
        <section class="bo-carte" aria-labelledby="diff-titre">
            <h2 id="diff-titre" class="!text-[1.15rem]">{{ __('admin.parametrage.differentiel') }}</h2>
            <div class="mt-2"><x-admin.differentiel :differentiel="$differentiel" :libelles="$libelles" :premiere="$premiereVersion" /></div>
        </section>
    @endif

    @if ($fiche->exists)
        @include('admin.parametrage._historique', ['versions' => $versions, 'decisions' => $decisions])
    @endif
</x-admin.layout>
