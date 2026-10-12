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
                @elseif ($campagne->statut === StatutCampagneBoutiques::Cloturee)
                    <x-admin.confirmation-renforcee id="tirer" :titre="__('admin.boutiques.actions.tirer')" :message="__('admin.boutiques.confirmations.tirer')"
                                                    :action="route('admin.boutiques.tirer', $campagne)" :bouton="__('admin.boutiques.actions.tirer')" :totp="$totpExige" />
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
                <thead><tr><th scope="col">{{ $k['libelle'] }}</th><th scope="col">{{ $k['description'] }}</th><th scope="col" class="montant">{{ $k['nombre_boutiques'] }}</th><th scope="col" class="montant">{{ $k['caution'] }}</th>
                    @foreach (['RESERVEE' => 'reservees', 'PAYEE' => 'payees', 'RETENUE' => 'retenues', 'NON_RETENUE' => 'non_retenues', 'EXPIREE' => 'expirees'] as $cle)
                        <th scope="col" class="montant">{{ __('admin.boutiques.candidatures.'.$cle) }}</th>
                    @endforeach
                </tr></thead>
                <tbody>
                    @foreach ($campagne->categories as $cat)
                        <tr><td class="font-semibold">{{ $cat->libelle }}</td><td>{{ $cat->description }}</td><td class="montant">{{ format_nombre($cat->nombre_boutiques) }}</td><td class="montant">{{ format_fcfa($cat->caution) }}</td>
                            @foreach (['RESERVEE', 'PAYEE', 'RETENUE', 'NON_RETENUE', 'EXPIREE'] as $statut)
                                <td class="montant">{{ format_nombre((int) ($compteurs[$cat->id][$statut] ?? 0)) }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    @if ($tirages->isNotEmpty())
        <section class="bo-carte" aria-labelledby="sec-tirage">
            <h2 id="sec-tirage" class="!text-[1.05rem]">{{ __('admin.boutiques.tirage.titre') }}</h2>
            <p class="texte-carte mt-1 max-w-3xl">{{ __('admin.boutiques.tirage.explication') }}</p>
            @foreach ($tirages as $tirage)
                <div class="mt-5 border-t border-ligne pt-4">
                    <h3 class="!text-base">{{ $tirage->categorie->libelle }} — {{ __('admin.boutiques.tirage.participants', ['n' => $tirage->nombre_participants, 'b' => $tirage->nombre_boutiques]) }}</h3>
                    <p class="texte-carte">{{ __('admin.boutiques.tirage.par', ['date' => $tirage->created_at->format('d/m/Y H:i'), 'nom' => $tirage->tireur->name]) }}</p>
                    <dl class="bo-definitions mt-2">
                        <dt>{{ __('admin.boutiques.tirage.graine') }}</dt><dd class="break-all font-mono text-sm">{{ $tirage->graine }}</dd>
                        <dt>{{ __('admin.boutiques.tirage.empreinte') }}</dt><dd class="break-all font-mono text-sm">{{ $tirage->empreinte_participants }}</dd>
                    </dl>
                    @if ($tirage->ordre)
                        <div class="mt-3 overflow-x-auto">
                            <table class="bo-tableau">
                                <thead><tr><th scope="col">{{ __('admin.boutiques.tirage.rang') }}</th><th scope="col">{{ __('admin.boutiques.tirage.reference') }}</th><th scope="col">{{ __('admin.boutiques.tirage.resultat') }}</th></tr></thead>
                                <tbody>
                                    @foreach ($tirage->ordre as $i => $reference)
                                        <tr><td>{{ $i + 1 }}</td><td class="font-mono">{{ $reference }}</td>
                                            <td>{{ $i < $tirage->nombre_boutiques ? __('admin.boutiques.candidatures.retenues') : __('admin.boutiques.candidatures.non_retenues') }}</td></tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            @endforeach
        </section>
    @endif

    @include('admin.parametrage._historique', ['versions' => $versions, 'decisions' => $decisions])
</x-admin.layout>
