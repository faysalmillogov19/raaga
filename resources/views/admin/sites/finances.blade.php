@php
    use App\Enums\Droit;
    use App\Enums\StatutGrilleTarifaire;

    $f = __('admin.sites.finances');
    $libelles = __('admin.sites.champs');
    $saisir = auth()->user()->can(Droit::BoutiquesSaisir->value);
    $modifiable = $saisir && (! $finances || $finances->estModifiable());
@endphp

<x-admin.layout :titre="$f['titre'].' · '.$site->nom" :fil="[['libelle' => __('admin.sites.titre'), 'url' => route('admin.sites')], ['libelle' => $site->nom, 'url' => route('admin.sites.show', $site)], ['libelle' => $f['titre']]]">
    <section class="bo-carte !mt-0" aria-labelledby="etat-titre">
        <h2 id="etat-titre" class="!text-[1.15rem]">{{ __('admin.parametrage.statut') }} :
            @if ($finances)<x-admin.badge-statut :statut="$finances->statut" />@else{{ $f['aucune'] }}@endif</h2>
        <p class="texte-carte mt-1 max-w-3xl">{{ $f['aide'] }}</p>
        @if ($finances?->motif_rejet && $finances->statut === StatutGrilleTarifaire::Brouillon)
            <x-admin.message type="erreur" class="mt-4 !mb-0">{{ __('admin.parametrage.motif_rejet', ['motif' => $finances->motif_rejet]) }}</x-admin.message>
        @endif

        @if ($finances)
            <dl class="mt-4 grid gap-3 sm:grid-cols-2">
                <div><dt class="texte-carte">{{ ucfirst($libelles['cout_total']) }}</dt><dd class="font-bold">{{ $finances->cout_total !== null ? format_nombre($finances->cout_total) : '—' }}</dd></div>
                <div><dt class="texte-carte">{{ ucfirst($libelles['part_occupants']) }}</dt><dd class="font-bold">{{ $finances->part_occupants !== null
                    ? ($finances->partOccupantsPourcent() !== null ? __('admin.sites.finances.part', ['montant' => format_nombre($finances->part_occupants), 'pourcent' => format_nombre($finances->partOccupantsPourcent(), 1).' %']) : format_nombre($finances->part_occupants)) : '—' }}</dd></div>
                <div><dt class="texte-carte">{{ $f['complement'] }}</dt><dd class="font-bold">{{ $finances->complement() !== null ? format_nombre($finances->complement()) : '—' }}</dd></div>
                <div><dt class="texte-carte">{{ ucfirst($libelles['source_complement']) }}</dt><dd class="font-bold">{{ $finances->source_complement ?? '—' }}</dd></div>
            </dl>
        @endif

        <div class="mt-4 flex flex-wrap gap-3">
            @if ($modifiable)
                <x-admin.fenetre id="modifier-finances" :titre="$f['titre'].' · '.$site->nom" :action="route('admin.sites.finances.update', $site)" methode="PUT"
                                 :bouton="__('admin.composants.circuit.brouillon')" declencheur-variante="secondaire" soumission>
                    <x-slot:declencheur>{{ __('admin.parametrage.actions.modifier') }}</x-slot:declencheur>
                    <x-admin.champ nom="cout_total" :libelle="ucfirst($libelles['cout_total'])" type="number" min="0" :valeur="$finances?->cout_total" />
                    <x-admin.champ nom="part_occupants" :libelle="ucfirst($libelles['part_occupants'])" type="number" min="0" :valeur="$finances?->part_occupants" class="mt-4" />
                    <x-admin.champ nom="source_complement" :libelle="ucfirst($libelles['source_complement'])" maxlength="200" :valeur="$finances?->source_complement" class="mt-4" />
                </x-admin.fenetre>
            @endif
            @if ($modifiable && $finances)
                <x-admin.confirmation etape="soumettre" id="soumettre" :titre="__('admin.parametrage.actions.soumettre')" :message="__('admin.parametrage.confirmations.soumettre')"
                                      :action="route('admin.sites.finances.soumettre', $site)" :bouton="__('admin.parametrage.actions.soumettre')">
                    <x-slot:declencheur>{{ __('admin.parametrage.actions.soumettre') }}</x-slot:declencheur>
                </x-admin.confirmation>
            @elseif ($saisir && $finances?->statut === StatutGrilleTarifaire::Confirme)
                <x-admin.confirmation etape="reviser" id="reviser" :titre="__('admin.grilles.actions.reviser')" :message="__('admin.grilles.confirmations.reviser')"
                                      :action="route('admin.sites.finances.reviser', $site)" :bouton="__('admin.grilles.actions.reviser')">
                    <x-slot:declencheur>{{ __('admin.grilles.actions.reviser') }}</x-slot:declencheur>
                </x-admin.confirmation>
            @endif

            @can(Droit::BoutiquesConfirmer->value)
                @if ($finances?->estSoumis())
                    <x-admin.confirmation etape="confirmer" id="confirmer" :titre="__('admin.parametrage.actions.confirmer')" :message="__('admin.parametrage.confirmations.confirmer')"
                                          :action="route('admin.sites.finances.confirmer', $site)" :bouton="__('admin.parametrage.actions.confirmer')">
                        <x-slot:declencheur>{{ __('admin.parametrage.actions.confirmer') }}</x-slot:declencheur>
                    </x-admin.confirmation>
                    <x-admin.confirmation etape="rejeter" id="rejeter" :titre="__('admin.parametrage.actions.rejeter')" :message="__('admin.parametrage.confirmations.rejeter')"
                                          :action="route('admin.sites.finances.rejeter', $site)" :bouton="__('admin.parametrage.actions.rejeter')" motif danger>
                        <x-slot:declencheur>{{ __('admin.parametrage.actions.rejeter') }}</x-slot:declencheur>
                    </x-admin.confirmation>
                @endif
            @endcan
        </div>
        @error('motif') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
    </section>

    @if ($finances?->estSoumis())
        <section class="bo-carte" aria-labelledby="diff-titre">
            <h2 id="diff-titre" class="!text-[1.15rem]">{{ __('admin.parametrage.differentiel') }}</h2>
            <div class="mt-2"><x-admin.differentiel :differentiel="$differentiel" :libelles="$libelles" :premiere="$premiereVersion" /></div>
        </section>
    @endif


    @if ($finances)
        @include('admin.parametrage._historique', ['versions' => $versions, 'decisions' => $decisions])
    @endif
</x-admin.layout>
