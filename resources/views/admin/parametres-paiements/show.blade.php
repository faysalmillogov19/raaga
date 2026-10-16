{{--
    Délais de paiement : caution (72 heures par défaut), complément d'un paiement incomplet (48 heures), avance à compter
    du passage de la boutique en « réservée » (30 jours). Chaque délai : valeur et durée obligatoires, valeur en secondes
    calculée. RÉGULATEUR : saisie (fenêtre) et soumission ; VALIDATEUR : validation ou rejet. Les délais confirmés
    s'appliquent, même pendant une révision.
--}}
@php
    use App\Enums\Droit;
    use App\Enums\StatutGrilleTarifaire;
    use App\Models\Duree;
    use App\Models\ParametresPaiements;

    $i = __('admin.delais');
    $libelles = $i['champs'];
    $saisir = auth()->user()->can(Droit::MoyensPaiementGerer->value);
    $modifiable = $saisir && (! $fiche->exists || $fiche->estModifiable());
    $optionsDurees = $durees->mapWithKeys(fn (Duree $d) => [$d->code => ucfirst($d->libelle_pluriel)])->all();
@endphp

<x-admin.layout :titre="$i['titre']" :sous-titre="$i['intro']" :fil="[['libelle' => $i['titre']]]">
    <section class="bo-carte !mt-0" aria-labelledby="etat-titre">
        <h2 id="etat-titre" class="!text-[1.15rem]">{{ __('admin.parametrage.statut') }} :
            @if ($fiche->exists)<x-admin.badge-statut :statut="$fiche->statut" />@else{{ $i['aucun'] }}@endif</h2>
        @if ($fiche->motif_rejet && $fiche->statut === StatutGrilleTarifaire::Brouillon)
            <x-admin.message type="erreur" class="mt-4 !mb-0">{{ __('admin.parametrage.motif_rejet', ['motif' => $fiche->motif_rejet]) }}</x-admin.message>
        @endif

        <table class="bo-tableau mt-4" data-delais>
            <thead><tr><th scope="col">{{ $i['colonnes']['delai'] }}</th><th scope="col">{{ $i['colonnes']['en_vigueur'] }}</th>
                @if ($fiche->exists)<th scope="col">{{ $i['colonnes']['saisi'] }}</th>@endif</tr></thead>
            <tbody>
                @foreach (array_keys(ParametresPaiements::DELAIS) as $d)
                    <tr data-delai="{{ $d }}">
                        <td><span class="font-semibold text-encre">{{ $i['delais'][$d] }}</span><br><span class="texte-carte">{{ $i['aides'][$d] }}</span></td>
                        <td class="font-bold" data-en-vigueur>{{ $enVigueur[$d] }}</td>
                        @if ($fiche->exists)<td data-saisie>{{ $fiche->libelleSaisi($d) ?? '' }}</td>@endif
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="mt-4 flex flex-wrap gap-3">
            @if ($modifiable)
                <x-admin.fenetre id="modifier-delais" :titre="$i['titre']" :action="route('admin.parametres-paiements.update')" methode="PUT"
                                 :bouton="__('admin.composants.circuit.brouillon')" declencheur-variante="secondaire">
                    <x-slot:declencheur>{{ __('admin.parametrage.actions.modifier') }}</x-slot:declencheur>
                    <p class="texte-carte mb-3">{{ $i['aide_saisie'] }}</p>
                    @foreach (ParametresPaiements::DELAIS as $d => [$valeurDefaut, $dureeDefaut])
                        @php($duree = Duree::parId($fiche->{'delai_'.$d.'_duree_id'}))
                        <fieldset class="adm-fenetre-grille mt-5" data-saisie-delai="{{ $d }}">
                            <legend class="adm-plein font-semibold">{{ $i['delais'][$d] }}</legend>
                            <x-admin.champ :nom="'delai_'.$d.'_valeur'" :libelle="$i['valeur']" type="number" min="1" max="999999" obligatoire
                                           :valeur="$fiche->{'delai_'.$d.'_valeur'} ?? $valeurDefaut" />
                            <x-admin.champ :nom="'delai_'.$d.'_duree'" :libelle="$i['duree']" type="liste" obligatoire :options="$optionsDurees"
                                           :valeur="$duree?->code ?? $dureeDefaut" :vide="$i['choisir_duree']" />
                        </fieldset>
                    @endforeach
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
