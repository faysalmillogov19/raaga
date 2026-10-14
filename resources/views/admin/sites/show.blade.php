@php
    use App\Enums\Droit;
    use App\Enums\StatutSite;

    $gerer = auth()->user()->can(Droit::SitesGerer->value);
    $modifiable = $gerer && $site->estModifiable();
    $libelles = __('admin.sites.champs');
@endphp

<x-admin.layout :titre="$site->nom" :fil="[['libelle' => __('admin.sites.titre'), 'url' => route('admin.sites')], ['libelle' => $site->nom]]">
    {{-- Statut et actions --}}
    <section class="bo-carte !mt-0" aria-labelledby="etat-titre">
        <h2 id="etat-titre" class="!text-[1.15rem]">{{ __('admin.parametrage.statut') }} : @include('admin.parametrage._statut', ['statut' => $site->statut])</h2>
        <p class="texte-carte mt-1">@if ($site->code)<span class="font-mono font-bold text-encre">{{ $site->code }}</span> · @endif{{ $site->localite() }}@if ($site->arrondissement) · {{ $site->arrondissement }}@endif @if ($site->adresse)— {{ $site->adresse }}@endif</p>
        <p class="texte-carte">{{ $site->version ? __('admin.parametrage.version', ['n' => $site->version]) : __('admin.parametrage.aucune_version') }}</p>
        @if ($site->description)<p class="mt-2 max-w-3xl whitespace-pre-line">{{ $site->description }}</p>@endif

        @if ($site->motif_rejet && $site->statut === StatutSite::Brouillon)
            <p class="bo-message bo-message-erreur mt-4 !mb-0" role="alert">{{ __('admin.parametrage.motif_rejet', ['motif' => $site->motif_rejet]) }}</p>
        @endif

        <div class="mt-4 flex flex-wrap gap-3">
            @if ($modifiable)
                <a href="{{ route('admin.sites.edit', $site) }}" class="bo-bouton bo-bouton-secondaire">{{ __('admin.parametrage.actions.modifier') }}</a>
                <x-admin.confirmation id="soumettre" :titre="__('admin.parametrage.actions.soumettre')" :message="__('admin.parametrage.confirmations.soumettre')"
                                      :action="route('admin.sites.soumettre', $site)" :bouton="__('admin.parametrage.actions.soumettre')">
                    <x-slot:declencheur>{{ __('admin.parametrage.actions.soumettre') }}</x-slot:declencheur>
                </x-admin.confirmation>
            @elseif ($gerer && $site->statut === StatutSite::Confirme)
                <x-admin.confirmation id="reviser" :titre="__('admin.sites.actions.reviser')" :message="__('admin.sites.confirmations.reviser')"
                                      :action="route('admin.sites.reviser', $site)" :bouton="__('admin.sites.actions.reviser')">
                    <x-slot:declencheur>{{ __('admin.sites.actions.reviser') }}</x-slot:declencheur>
                </x-admin.confirmation>
            @endif

            @can(Droit::SitesConfirmer->value)
                @if ($site->estSoumis())
                    <x-admin.confirmation id="confirmer" :titre="__('admin.parametrage.actions.confirmer')" :message="__('admin.parametrage.confirmations.confirmer')"
                                          :action="route('admin.sites.confirmer', $site)" :bouton="__('admin.parametrage.actions.confirmer')">
                        <x-slot:declencheur>{{ __('admin.parametrage.actions.confirmer') }}</x-slot:declencheur>
                    </x-admin.confirmation>
                    <x-admin.confirmation id="rejeter" :titre="__('admin.parametrage.actions.rejeter')" :message="__('admin.parametrage.confirmations.rejeter')"
                                          :action="route('admin.sites.rejeter', $site)" :bouton="__('admin.parametrage.actions.rejeter')" motif danger>
                        <x-slot:declencheur>{{ __('admin.parametrage.actions.rejeter') }}</x-slot:declencheur>
                    </x-admin.confirmation>
                @endif
            @endcan
        </div>
        @error('motif') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
    </section>

    @if ($site->estSoumis())
        <section class="bo-carte" aria-labelledby="diff-titre">
            <h2 id="diff-titre" class="!text-[1.15rem]">{{ __('admin.parametrage.differentiel') }}</h2>
            <div class="mt-2"><x-admin.differentiel :differentiel="$differentiel" :libelles="$libelles" :premiere="$premiereVersion" /></div>
        </section>
    @endif

    @include('admin.sites._fiche')

    {{-- Données financières : jamais pour le GESTIONNAIRE DES SITES ET BOUTIQUES (écran protégé côté serveur). --}}
    @can(Droit::BoutiquesVoir->value)
        <section class="bo-carte" aria-labelledby="finances-titre">
            <h2 id="finances-titre" class="!text-[1.15rem]">{{ __('admin.sites.finances.titre') }}
                @if ($site->finances)@include('admin.parametrage._statut', ['statut' => $site->finances->statut])@endif</h2>
            <p class="texte-carte mt-1 max-w-3xl">{{ __('admin.sites.finances.aide') }}</p>
            <a href="{{ route('admin.sites.finances', $site) }}" class="bo-bouton bo-bouton-secondaire mt-3">{{ __('admin.sites.finances.voir') }}</a>
        </section>
    @endcan
    @include('admin.sites._capacites')

    @include('admin.parametrage._historique', ['versions' => $versions, 'decisions' => $decisions])
</x-admin.layout>
