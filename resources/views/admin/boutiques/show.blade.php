@php
    use App\Enums\Droit;
    use App\Enums\StatutCampagneBoutiques;
    use App\Support\AffichageParametres;

    $libelles = __('admin.boutiques.champs');
    $r = __('admin.boutiques.reservations');
    $snapshot = $campagne->snapshotParametres();
@endphp

<x-admin.layout :titre="$campagne->nom" :fil="[['libelle' => __('admin.boutiques.titre'), 'url' => route('admin.boutiques')], ['libelle' => $campagne->nom]]">
    @if ($fermeture = $campagne->raisonPaiementsFermes())
        <x-admin.message type="avertissement" data-paiements-fermes>{{ __('admin.boutiques.regles.paiements_fermes', ['raison' => $fermeture]) }}</x-admin.message>
    @endif
    {{-- Statut et actions --}}
    <section class="bo-carte !mt-0" aria-labelledby="etat-titre">
        <h2 id="etat-titre" class="!text-[1.15rem]">{{ __('admin.parametrage.statut') }} : <x-admin.badge-statut :statut="$campagne->statut" /></h2>
        <p class="texte-carte mt-1">{{ $campagne->version ? __('admin.parametrage.version', ['n' => $campagne->version]) : __('admin.parametrage.aucune_version') }}</p>

        @if ($campagne->motif_rejet && $campagne->statut === StatutCampagneBoutiques::Brouillon)
            <x-admin.message type="erreur" class="mt-4 !mb-0">{{ __('admin.parametrage.motif_rejet', ['motif' => $campagne->motif_rejet]) }}</x-admin.message>
        @endif

        <div class="mt-4 flex flex-wrap gap-3">
            @can(Droit::BoutiquesSaisir->value)
                @if ($campagne->estModifiable())
                    <x-admin.bouton variante="contour" href="{{ route('admin.boutiques.edit', $campagne) }}">{{ __('admin.parametrage.actions.modifier') }}</x-admin.bouton>
                    <x-admin.confirmation etape="soumettre" id="soumettre" :titre="__('admin.parametrage.actions.soumettre')" :message="__('admin.parametrage.confirmations.soumettre')"
                                          :action="route('admin.boutiques.soumettre', $campagne)" :bouton="__('admin.parametrage.actions.soumettre')">
                        <x-slot:declencheur>{{ __('admin.parametrage.actions.soumettre') }}</x-slot:declencheur>
                    </x-admin.confirmation>
                @endif
            @endcan

            @can(Droit::BoutiquesConfirmer->value)
                @if ($campagne->estSoumis())
                    <x-admin.confirmation etape="confirmer" id="confirmer" :titre="__('admin.parametrage.actions.confirmer')" :message="__('admin.parametrage.confirmations.confirmer')"
                                          :action="route('admin.boutiques.confirmer', $campagne)" :bouton="__('admin.parametrage.actions.confirmer')">
                        <x-slot:declencheur>{{ __('admin.parametrage.actions.confirmer') }}</x-slot:declencheur>
                    </x-admin.confirmation>
                    <x-admin.confirmation etape="rejeter" id="rejeter" :titre="__('admin.parametrage.actions.rejeter')" :message="__('admin.parametrage.confirmations.rejeter')"
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
                <dt>{{ ucfirst($libelles['sites']) }}</dt><dd>{{ $snapshot['sites'] ?: '' }}</dd>
                <dt>{{ ucfirst($libelles['max_boutiques_par_personne']) }}</dt><dd>{{ $campagne->max_boutiques_par_personne }}</dd>
                <dt>{{ ucfirst($libelles['canaux_actifs']) }}</dt><dd>{{ AffichageParametres::valeur('canaux_actifs', $snapshot['canaux_actifs']) }}</dd>
                <dt>{{ ucfirst($libelles['delai_paiement']) }}</dt><dd data-delai-caution>{{ $campagne->libelleDelaiPaiement() }}
                    <span class="texte-carte">({{ __($campagne->aSonPropreDelai() ? 'admin.boutiques.aides.delai_propre_court' : 'admin.boutiques.aides.delai_parametre_court') }})</span></dd>
            </dl>
            @if ($sitesSansGrille)
                <x-admin.message type="avertissement" class="mt-3 !mb-0">{{ __('admin.boutiques.regles.sans_grille', ['lots' => implode(', ', $sitesSansGrille)]) }}</x-admin.message>
            @endif
        </section>
    </div>

    {{-- Boutiques des sites de la campagne, par statut (au premier arrivé). --}}
    <section class="bo-carte" aria-labelledby="sec-compteurs">
        <h2 id="sec-compteurs" class="!text-[1.05rem]">{{ $r['statuts_titre'] }}</h2>
        <div class="adm-cartes mt-3" data-compteurs-campagne>
            @foreach ($compteurs as $cle => $n)
                <div class="adm-indicateur" data-statut="{{ $cle }}">
                    <span class="adm-indicateur-libelle">{{ __('public.boutiques.plan.statuts.'.$cle) }}</span>
                    <span class="adm-indicateur-valeur">{{ format_nombre($n) }}</span>
                </div>
            @endforeach
        </div>
    </section>

    <section class="bo-carte" aria-labelledby="sec-reservations">
        <h2 id="sec-reservations" class="!text-[1.05rem]">{{ $r['titre'] }}</h2>
        <p class="texte-carte mt-1">{{ $r['intro'] }}</p>
        <div class="mt-3"><x-tableau :t="$tableau" :libelle="$r['titre']" /></div>
    </section>

    @if ($historiqueTirages->isNotEmpty())
        {{-- Ancien tirage au sort : données conservées en lecture seule. --}}
        <section class="bo-carte" aria-labelledby="sec-historique-tirage" data-historique-tirage>
            <h2 id="sec-historique-tirage" class="!text-[1.05rem]">{{ $r['historique_tirage'] }}</h2>
            <ul class="mt-2 space-y-1">
                @foreach ($historiqueTirages as $t)
                    <li class="texte-carte">{{ \Illuminate\Support\Carbon::parse($t->created_at)->format('d/m/Y H:i') }} · {{ trans_choice('admin.boutiques.reservations.tirage_participants', $t->nombre_participants, ['n' => $t->nombre_participants, 'b' => $t->nombre_boutiques]) }} · <span class="font-mono">{{ $t->graine }}</span></li>
                @endforeach
            </ul>
        </section>
    @endif

    @include('admin.parametrage._historique', ['versions' => $versions, 'decisions' => $decisions])
</x-admin.layout>
