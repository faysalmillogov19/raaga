@php
    use App\Enums\Droit;
    use App\Enums\StatutCampagneBoutiques;
    use App\Support\AffichageParametres;

    $libelles = __('admin.boutiques.champs');
    $k = __('admin.boutiques.categorie');
    $snapshot = $campagne->snapshotParametres();
@endphp

<x-admin.layout :titre="$campagne->nom" :fil="[['libelle' => __('admin.boutiques.titre'), 'url' => route('admin.boutiques')], ['libelle' => $campagne->nom]]">
    {{-- Statut et actions --}}
    <section class="bo-carte !mt-0" aria-labelledby="etat-titre">
        <h2 id="etat-titre" class="!text-[1.15rem]">{{ __('admin.parametrage.statut') }} : @include('admin.parametrage._statut', ['statut' => $campagne->statut])</h2>
        <p class="texte-carte mt-1">{{ $campagne->version ? __('admin.parametrage.version', ['n' => $campagne->version]) : __('admin.parametrage.aucune_version') }}</p>

        @if ($campagne->motif_rejet && $campagne->statut === StatutCampagneBoutiques::Brouillon)
            <p class="bo-message bo-message-erreur mt-4 !mb-0" role="alert">{{ __('admin.parametrage.motif_rejet', ['motif' => $campagne->motif_rejet]) }}</p>
        @endif

        <div class="mt-4 flex flex-wrap gap-3">
            @can(Droit::BoutiquesSaisir->value)
                @if ($campagne->estModifiable())
                    <a href="{{ route('admin.boutiques.edit', $campagne) }}" class="bo-bouton bo-bouton-secondaire">{{ __('admin.parametrage.actions.modifier') }}</a>
                    <x-admin.confirmation id="soumettre" :titre="__('admin.parametrage.actions.soumettre')" :message="__('admin.parametrage.confirmations.soumettre')"
                                          :action="route('admin.boutiques.soumettre', $campagne)" :bouton="__('admin.parametrage.actions.soumettre')">
                        <x-slot:declencheur>{{ __('admin.parametrage.actions.soumettre') }}</x-slot:declencheur>
                    </x-admin.confirmation>
                @endif
            @endcan

            @can(Droit::BoutiquesConfirmer->value)
                @if ($campagne->estSoumis())
                    <x-admin.confirmation id="confirmer" :titre="__('admin.parametrage.actions.confirmer')" :message="__('admin.parametrage.confirmations.confirmer')"
                                          :action="route('admin.boutiques.confirmer', $campagne)" :bouton="__('admin.parametrage.actions.confirmer')">
                        <x-slot:declencheur>{{ __('admin.parametrage.actions.confirmer') }}</x-slot:declencheur>
                    </x-admin.confirmation>
                    <x-admin.confirmation id="rejeter" :titre="__('admin.parametrage.actions.rejeter')" :message="__('admin.parametrage.confirmations.rejeter')"
                                          :action="route('admin.boutiques.rejeter', $campagne)" :bouton="__('admin.parametrage.actions.rejeter')" motif danger>
                        <x-slot:declencheur>{{ __('admin.parametrage.actions.rejeter') }}</x-slot:declencheur>
                    </x-admin.confirmation>
                @endif
            @endcan
            @can(Droit::BoutiquesPiloter->value)
                @if ($campagne->statut === StatutCampagneBoutiques::Confirmee)
                    <x-admin.confirmation-renforcee id="ouvrir" :titre="__('admin.boutiques.actions.ouvrir')" :message="__('admin.boutiques.confirmations.ouvrir')"
                                                    :action="route('admin.boutiques.ouvrir', $campagne)" :bouton="__('admin.boutiques.actions.ouvrir')" :totp="$totpExige" />
                @elseif ($campagne->statut === StatutCampagneBoutiques::Ouverte)
                    <x-admin.confirmation-renforcee id="cloturer" :titre="__('admin.boutiques.actions.cloturer')" :message="__('admin.boutiques.confirmations.cloturer')"
                                                    :action="route('admin.boutiques.cloturer', $campagne)" :bouton="__('admin.boutiques.actions.cloturer')" :totp="$totpExige" />
                @endif
            @endcan
        </div>
        @error('motif') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
    </section>

    @if ($campagne->estSoumis())
        <section class="bo-carte" aria-labelledby="diff-titre">
            <h2 id="diff-titre" class="!text-[1.15rem]">{{ __('admin.parametrage.differentiel') }}</h2>
            <div class="mt-2"><x-admin.differentiel :differentiel="$differentiel" :libelles="$libelles" :premiere="$premiereVersion" /></div>
        </section>
    @endif

    <div class="my-[18px] grid gap-[18px] xl:grid-cols-2">
        <section class="bo-carte !mt-0" aria-labelledby="sec-campagne">
            <h2 id="sec-campagne" class="!text-[1.05rem]">{{ __('admin.boutiques.sections.campagne') }}</h2>
            <dl class="bo-definitions mt-3">
                <dt>{{ ucfirst($libelles['nom']) }}</dt><dd>{{ $campagne->nom }}</dd>
                <dt>{{ ucfirst($libelles['date_ouverture']) }}</dt><dd>{{ $campagne->date_ouverture->format('d/m/Y H:i') }}</dd>
                <dt>{{ ucfirst($libelles['date_cloture']) }}</dt><dd>{{ $campagne->date_cloture->format('d/m/Y H:i') }}</dd>
                @if ($campagne->description)
                    <dt>{{ ucfirst($libelles['description']) }}</dt><dd class="whitespace-pre-line">{{ $campagne->description }}</dd>
                @endif
            </dl>
        </section>
        <section class="bo-carte !mt-0" aria-labelledby="sec-paiement">
            <h2 id="sec-paiement" class="!text-[1.05rem]">{{ __('admin.boutiques.sections.paiement') }}</h2>
            <dl class="bo-definitions mt-3">
                <dt>{{ ucfirst($libelles['canaux_actifs']) }}</dt><dd>{{ AffichageParametres::valeur('canaux_actifs', $snapshot['canaux_actifs']) }}</dd>
                <dt>{{ ucfirst($libelles['delai_paiement_heures']) }}</dt><dd>{{ $campagne->delai_paiement_heures }} h</dd>
            </dl>
        </section>
    </div>

    <section class="bo-carte" aria-labelledby="sec-categories">
        <h2 id="sec-categories" class="!text-[1.05rem]">{{ $k['titre'] }}</h2>
        <div class="mt-3 overflow-x-auto">
            <table class="bo-tableau">
                <thead><tr><th scope="col">{{ $k['libelle'] }}</th><th scope="col">{{ $k['description'] }}</th><th scope="col" class="montant">{{ $k['nombre_boutiques'] }}</th><th scope="col" class="montant">{{ $k['caution'] }}</th></tr></thead>
                <tbody>
                    @foreach ($campagne->categories as $cat)
                        <tr><td class="font-semibold">{{ $cat->libelle }}</td><td>{{ $cat->description }}</td><td class="montant">{{ format_nombre($cat->nombre_boutiques) }}</td><td class="montant">{{ format_fcfa($cat->caution) }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    @include('admin.parametrage._historique', ['versions' => $versions, 'decisions' => $decisions])
</x-admin.layout>
