@php
    $libellesAudit = __('admin.utilisateurs.audit');
@endphp

<x-admin.layout :titre="$utilisateur->name" :fil="[
    ['libelle' => __('admin.menu.assistance'), 'url' => route('admin.assistance')],
    ['libelle' => $utilisateur->name],
]">
    <div class="grid gap-[18px] xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
        <section class="bo-carte !mt-0" aria-labelledby="identite-titre">
            <h2 id="identite-titre" class="!text-[1.15rem]">{{ __('admin.utilisateurs.fiche.identite') }}</h2>
            <dl class="bo-definitions mt-3">
                <dt>{{ __('admin.utilisateurs.fiche.nom') }}</dt><dd>{{ $utilisateur->nom ?? '—' }}</dd>
                <dt>{{ __('admin.utilisateurs.fiche.prenoms') }}</dt><dd>{{ $utilisateur->prenoms ?? '—' }}</dd>
                <dt>{{ __('admin.utilisateurs.fiche.naissance') }}</dt><dd>{{ $utilisateur->date_naissance?->format('d/m/Y') ?? '—' }}</dd>
                <dt>{{ __('admin.utilisateurs.fiche.nationalite') }}</dt><dd>{{ $utilisateur->nationalite ?? '—' }}</dd>
                <dt>{{ __('admin.utilisateurs.fiche.pays') }}</dt><dd>{{ config('raaga.inscription.pays')[$utilisateur->pays_residence] ?? ($utilisateur->pays_residence ?? '—') }}</dd>
                <dt>{{ __('admin.utilisateurs.fiche.region') }}</dt><dd>{{ $utilisateur->region ?? '—' }}</dd>
                <dt>{{ __('admin.utilisateurs.fiche.telephone') }}</dt><dd>{{ $utilisateur->telephone ? \App\Support\Telephone::formater($utilisateur->telephone) : '—' }}</dd>
                <dt>{{ __('admin.utilisateurs.fiche.email') }}</dt><dd>{{ $utilisateur->email ?? '—' }}</dd>
                <dt>{{ __('admin.assistance.type') }}</dt><dd>{{ $utilisateur->codeRole()->label() }}</dd>
                <dt>{{ __('admin.utilisateurs.fiche.kyc') }}</dt><dd>{{ $utilisateur->statut_kyc?->label() ?? '—' }}</dd>
                <dt>{{ __('admin.utilisateurs.fiche.souscriptions') }}</dt>
                <dd>{{ __('admin.utilisateurs.fiche.nb_souscriptions', ['n' => $souscriptions->nombre ?? 0, 'montant' => format_fcfa((int) ($souscriptions->montant ?? 0))]) }}</dd>
                <dt>{{ __('admin.utilisateurs.fiche.statut') }}</dt>
                <dd>@include('admin.utilisateurs._statut', ['u' => $utilisateur, 'invitation' => false])</dd>
                <dt>{{ __('admin.utilisateurs.fiche.inscription') }}</dt><dd>{{ $utilisateur->created_at?->format('d/m/Y H:i') }}</dd>
            </dl>
            <p class="texte-carte mt-4">{{ __('admin.assistance.lecture_identite') }}</p>
        </section>

        <section class="bo-carte !mt-0" aria-labelledby="actions-titre">
            <h2 id="actions-titre" class="!text-[1.15rem]">{{ __('admin.utilisateurs.fiche.actions') }}</h2>
            @can(\App\Enums\Droit::AssistanceAgir->value)
                <div class="mt-3 flex flex-wrap gap-3">
                    @if ($utilisateur->actif)
                        <x-admin.confirmation id="desactiver" :titre="__('admin.utilisateurs.actions.desactiver')"
                                              :message="__('admin.assistance.confirmations.desactiver')"
                                              :action="route('admin.assistance.desactiver', $utilisateur)"
                                              :bouton="__('admin.utilisateurs.actions.desactiver')" motif danger>
                            <x-slot:declencheur>{{ __('admin.utilisateurs.actions.desactiver') }}</x-slot:declencheur>
                        </x-admin.confirmation>
                    @else
                        <x-admin.confirmation id="reactiver" :titre="__('admin.utilisateurs.actions.reactiver')"
                                              :message="__('admin.assistance.confirmations.reactiver')"
                                              :action="route('admin.assistance.reactiver', $utilisateur)"
                                              :bouton="__('admin.utilisateurs.actions.reactiver')" motif>
                            <x-slot:declencheur>{{ __('admin.utilisateurs.actions.reactiver') }}</x-slot:declencheur>
                        </x-admin.confirmation>
                    @endif
                </div>
                <p class="texte-carte mt-3">{{ __('admin.assistance.actions_a_venir') }}</p>
                @error('motif') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
            @endcan
        </section>
    </div>

    <section class="bo-carte" aria-labelledby="acceptations-titre">
        <h2 id="acceptations-titre" class="!text-[1.15rem]">{{ __('admin.utilisateurs.fiche.acceptations') }}</h2>
        @if ($acceptations->isEmpty())
            <p class="texte-carte mt-2">{{ __('admin.utilisateurs.fiche.aucune_acceptation') }}</p>
        @else
            <ul class="mt-2 space-y-1">
                @foreach ($acceptations as $acceptation)
                    <li>{{ \App\Enums\DocumentAccepte::tryFrom($acceptation->document)?->label() ?? $acceptation->document }} — version {{ $acceptation->version_document }} — {{ \Carbon\Carbon::parse($acceptation->accepte_le)->format('d/m/Y H:i') }}</li>
                @endforeach
            </ul>
        @endif
    </section>

    <section class="bo-carte" aria-labelledby="historique-titre">
        <h2 id="historique-titre" class="!text-[1.15rem]">{{ __('admin.assistance.historique') }}</h2>
        @if ($historique->isEmpty())
            <p class="texte-carte mt-2">{{ __('admin.utilisateurs.fiche.aucun_historique') }}</p>
        @else
            <div class="mt-3 overflow-x-auto">
                <table class="bo-tableau">
                    <tbody>
                        @foreach ($historique as $entree)
                            <tr>
                                <td class="whitespace-nowrap">{{ $entree->created_at?->format('d/m/Y H:i') }}</td>
                                <td>{{ $libellesAudit[$entree->action] ?? $entree->action }}</td>
                                <td>{{ $entree->acteur?->name ?? __('admin.utilisateurs.fiche.acteur_systeme') }}</td>
                                <td class="texte-carte">{{ $entree->nouvelle_valeur['motif'] ?? '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</x-admin.layout>
