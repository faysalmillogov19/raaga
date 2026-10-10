@php
    use App\Enums\Droit;
    use App\Support\AffichageParametres;

    $c = __('institutionnel.bo.conventions');
    $libelles = $c['champs'];
    $snapshot = $convention->snapshotParametres();
@endphp

<x-admin.layout :titre="$convention->reference" :fil="[['libelle' => $c['titre'], 'url' => route('admin.conventions')], ['libelle' => $convention->reference]]">
    <section class="bo-carte !mt-0" aria-labelledby="etat-titre">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 id="etat-titre" class="!text-[1.15rem]">{{ __('admin.parametrage.statut') }} : @include('admin.parametrage._statut', ['statut' => $convention->statut])</h2>
                <p class="texte-carte mt-1">{{ $convention->institution->denomination }} · {{ $convention->version ? __('admin.parametrage.version', ['n' => $convention->version]) : __('admin.parametrage.aucune_version') }}</p>
            </div>
            @if ($convention->pdf_chemin)
                <a href="{{ route('admin.conventions.pdf', $convention) }}" class="bo-bouton bo-bouton-secondaire" target="_blank">{{ $c['pdf'] }}</a>
            @endif
        </div>

        @if ($convention->motif_rejet && $convention->estModifiable())
            <p class="bo-message bo-message-erreur mt-4 !mb-0" role="alert">{{ __('admin.parametrage.motif_rejet', ['motif' => $convention->motif_rejet]) }}</p>
        @endif

        <div class="mt-4 flex flex-wrap gap-3">
            @can(Droit::ConventionsSaisir->value)
                @if ($convention->estModifiable())
                    <a href="{{ route('admin.conventions.edit', $convention) }}" class="bo-bouton bo-bouton-secondaire">{{ __('admin.parametrage.actions.modifier') }}</a>
                    <x-admin.confirmation id="soumettre" :titre="__('admin.parametrage.actions.soumettre')" :message="__('admin.parametrage.confirmations.soumettre')"
                                          :action="route('admin.conventions.soumettre', $convention)" :bouton="__('admin.parametrage.actions.soumettre')">
                        <x-slot:declencheur>{{ __('admin.parametrage.actions.soumettre') }}</x-slot:declencheur>
                    </x-admin.confirmation>
                @elseif ($convention->champsAvenantAutorises() !== [])
                    <a href="{{ route('admin.conventions.avenant', $convention) }}" class="bo-bouton bo-bouton-secondaire">{{ __('admin.parametrage.actions.avenant') }}</a>
                @endif
            @endcan
            @can(Droit::ConventionsConfirmer->value)
                @if ($convention->estSoumis())
                    <x-admin.confirmation id="confirmer" :titre="__('admin.parametrage.actions.confirmer')" :message="__('admin.parametrage.confirmations.confirmer')"
                                          :action="route('admin.conventions.confirmer', $convention)" :bouton="__('admin.parametrage.actions.confirmer')">
                        <x-slot:declencheur>{{ __('admin.parametrage.actions.confirmer') }}</x-slot:declencheur>
                    </x-admin.confirmation>
                    <x-admin.confirmation id="rejeter" :titre="__('admin.parametrage.actions.rejeter')" :message="__('admin.parametrage.confirmations.rejeter')"
                                          :action="route('admin.conventions.rejeter', $convention)" :bouton="__('admin.parametrage.actions.rejeter')" motif danger>
                        <x-slot:declencheur>{{ __('admin.parametrage.actions.rejeter') }}</x-slot:declencheur>
                    </x-admin.confirmation>
                @endif
            @endcan
        </div>
        @error('motif') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
    </section>

    @if ($convention->estSoumis())
        <section class="bo-carte" aria-labelledby="diff-titre">
            <h2 id="diff-titre" class="!text-[1.15rem]">{{ __('admin.parametrage.differentiel') }}</h2>
            <div class="mt-2"><x-admin.differentiel :differentiel="$differentiel" :libelles="$libelles" :premiere="$premiereVersion" /></div>
        </section>
    @endif

    <div class="my-[18px] grid gap-[18px] xl:grid-cols-2">
        <section class="bo-carte !mt-0" aria-labelledby="parametres-titre">
            <h2 id="parametres-titre" class="!text-[1.05rem]">{{ $c['parametres'] }}</h2>
            <dl class="bo-definitions mt-3">
                @foreach (['reference', 'nb_actions', 'prix_unitaire', 'montant_engage', 'mode_inscription', 'droits_particuliers'] as $champ)
                    <dt>{{ ucfirst($libelles[$champ]) }}</dt><dd class="whitespace-pre-line">{{ AffichageParametres::valeur($champ, $snapshot[$champ]) }}</dd>
                @endforeach
            </dl>
        </section>

        <section class="bo-carte !mt-0" aria-labelledby="signature-titre">
            <h2 id="signature-titre" class="!text-[1.05rem]">{{ $c['signature'] }}</h2>
            @if ($convention->estSignee())
                <p class="mt-2">{{ __('institutionnel.bo.conventions.signee', ['date' => $convention->signee_le->format('d/m/Y à H:i'), 'nom' => $convention->signataire?->name, 'mode' => $convention->signature_mode->court()]) }}</p>
                <p class="texte-carte mt-1 break-all">{{ $convention->signature_empreinte }}</p>
            @elseif ($convention->signatureEnAttente())
                <p class="mt-2">{{ __('institutionnel.bo.conventions.depot', ['date' => $convention->signature_deposee_le->format('d/m/Y'), 'nom' => $convention->signataire?->name]) }}</p>
                <div class="mt-3 flex flex-wrap gap-3">
                    <a href="{{ route('admin.conventions.pdf-signe', $convention) }}" class="bo-bouton bo-bouton-secondaire" target="_blank">{{ $c['telecharger_signee'] }}</a>
                    @can(Droit::ConventionsConfirmer->value)
                        <x-admin.confirmation id="attester" :titre="$c['attester']" :message="$c['attester_message']" :action="route('admin.conventions.attester', $convention)" :bouton="$c['attester']">
                            <x-slot:declencheur>{{ $c['attester'] }}</x-slot:declencheur>
                        </x-admin.confirmation>
                    @endcan
                </div>
            @else
                <p class="texte-carte mt-2">{{ $c['non_signee'] }}</p>
            @endif
        </section>
    </div>

    <section class="bo-carte" aria-labelledby="echeancier-titre">
        <h2 id="echeancier-titre" class="!text-[1.15rem]">{{ $convention->appels->isNotEmpty() ? $c['appels'] : $libelles['echeancier'] }}</h2>
        @if ($convention->appels->isNotEmpty())
            <div class="mt-3 overflow-x-auto">
                <table class="bo-tableau">
                    <tbody>
                        @foreach ($convention->appels as $appel)
                            <tr>
                                <td>{{ $appel->numero }}</td>
                                <td><a href="{{ route('admin.appels-fonds.show', $appel) }}" class="font-bold text-vert-fonce underline underline-offset-2">{{ $appel->libelle }}</a></td>
                                <td>{{ $appel->echeance->format('d/m/Y') }}</td>
                                <td class="montant">{{ format_fcfa($appel->montant) }}</td>
                                <td class="montant">{{ format_fcfa($appel->montant_recu) }}</td>
                                <td><x-institutionnel.statut :statut="$appel->statut" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="mt-2">{{ AffichageParametres::valeur('echeancier', $convention->echeancier) }}</p>
        @endif
    </section>

    @include('admin.parametrage._avenants', [
        'avenants' => $avenants, 'libelles' => $libelles, 'objet' => $convention,
        'droitConfirmer' => Droit::ConventionsConfirmer->value,
        'routeConfirmer' => 'admin.conventions.avenant.confirmer', 'routeRejeter' => 'admin.conventions.avenant.rejeter',
    ])

    @include('admin.parametrage._historique', ['versions' => $versions, 'decisions' => $decisions])
</x-admin.layout>
