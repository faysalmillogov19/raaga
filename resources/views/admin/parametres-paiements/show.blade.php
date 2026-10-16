{{--
    Paramètres des paiements : délai de complément d'un paiement manuel incomplet (48 h par défaut). RÉGULATEUR : saisie
    (fenêtre) et soumission ; VALIDATEUR : validation ou rejet. Le délai confirmé s'applique, même pendant une révision.
--}}
@php
    use App\Enums\Droit;
    use App\Enums\StatutGrilleTarifaire;

    $i = __('admin.delais');
    $libelles = $i['champs'];
    $saisir = auth()->user()->can(Droit::MoyensPaiementGerer->value);
    $modifiable = $saisir && (! $fiche->exists || $fiche->estModifiable());
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
                <div><dt class="texte-carte">{{ ucfirst($libelles['delai_complement_heures']) }}</dt><dd class="font-bold">{{ $fiche->delai_complement_heures ? trans_choice('admin.delais.heures', $fiche->delai_complement_heures, ['n' => $fiche->delai_complement_heures]) : '' }}</dd></div>
            </dl>
        @endif
        <p class="texte-carte mt-3" data-en-vigueur>{{ __('admin.delais.en_vigueur', ['delai' => trans_choice('admin.delais.heures', $enVigueur, ['n' => $enVigueur])]) }}</p>

        <div class="mt-4 flex flex-wrap gap-3">
            @if ($modifiable)
                <x-admin.fenetre id="modifier-delais" :titre="$i['titre']" :action="route('admin.parametres-paiements.update')" methode="PUT"
                                 :bouton="__('admin.composants.circuit.brouillon')" declencheur-variante="secondaire">
                    <x-slot:declencheur>{{ __('admin.parametrage.actions.modifier') }}</x-slot:declencheur>
                    <x-admin.champ nom="delai_complement_heures" :libelle="ucfirst($libelles['delai_complement_heures'])" type="number" min="1" max="720"
                                   :valeur="$fiche->delai_complement_heures ?? $enVigueur" obligatoire :aide="$i['aides']['delai_complement_heures']" />
                </x-admin.fenetre>
            @endif
            @if ($modifiable && $fiche->exists)
                <x-admin.confirmation etape="soumettre" id="soumettre" :titre="__('admin.parametrage.actions.soumettre')" :message="__('admin.parametrage.confirmations.soumettre')"
                                      :action="route('admin.parametres-paiements.soumettre')" :bouton="__('admin.parametrage.actions.soumettre')">
                    <x-slot:declencheur>{{ __('admin.parametrage.actions.soumettre') }}</x-slot:declencheur>
                </x-admin.confirmation>
            @elseif ($saisir && $fiche->statut === StatutGrilleTarifaire::Confirme)
                <x-admin.confirmation etape="reviser" id="reviser" :titre="__('admin.grilles.actions.reviser')" :message="__('admin.grilles.confirmations.reviser')"
                                      :action="route('admin.parametres-paiements.reviser')" :bouton="__('admin.grilles.actions.reviser')">
                    <x-slot:declencheur>{{ __('admin.grilles.actions.reviser') }}</x-slot:declencheur>
                </x-admin.confirmation>
            @endif

            @can(Droit::MoyensPaiementConfirmer->value)
                @if ($fiche->estSoumis())
                    <x-admin.confirmation etape="confirmer" id="confirmer" :titre="__('admin.parametrage.actions.confirmer')" :message="__('admin.parametrage.confirmations.confirmer')"
                                          :action="route('admin.parametres-paiements.confirmer')" :bouton="__('admin.parametrage.actions.confirmer')">
                        <x-slot:declencheur>{{ __('admin.parametrage.actions.confirmer') }}</x-slot:declencheur>
                    </x-admin.confirmation>
                    <x-admin.confirmation etape="rejeter" id="rejeter" :titre="__('admin.parametrage.actions.rejeter')" :message="__('admin.parametrage.confirmations.rejeter')"
                                          :action="route('admin.parametres-paiements.rejeter')" :bouton="__('admin.parametrage.actions.rejeter')" motif danger>
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
