@php
    use App\Enums\Droit;
    use App\Enums\StatutCampagneBoutiques;

    $libelles = __('admin.boutiques.champs');
    $r = __('admin.boutiques.reservations');
@endphp

<x-admin.layout :titre="$campagne->nom" :fil="[['libelle' => __('admin.boutiques.titre'), 'url' => route('admin.boutiques')], ['libelle' => $campagne->nom]]">
    @if ($fermeture = $campagne->raisonPaiementsFermes())
        <x-admin.message type="avertissement" data-paiements-fermes>{{ __('admin.boutiques.regles.paiements_fermes', ['raison' => $fermeture]) }}</x-admin.message>
    @endif
    {{-- Statut et actions --}}
    <section class="bo-carte !mt-0" aria-labelledby="etat-titre">
        <h2 id="etat-titre" class="!text-[1.15rem]">{{ __('admin.parametrage.statut') }} : <x-admin.badge-statut :statut="$campagne->statut" /></h2>
        <p class="texte-carte mt-1">{{ $campagne->version ? __('admin.parametrage.version', ['n' => $campagne->version]) : __('admin.parametrage.aucune_version') }}</p>

        {{-- Ouverture et clôture automatiques aux dates de la campagne (EcheancesCampagnes). --}}
        @if ($campagne->statut === StatutCampagneBoutiques::Confirmee)
            @if ($blocageOuverture && $campagne->date_ouverture->isPast())
                <x-admin.message type="avertissement" class="mt-4 !mb-0" data-ouverture-attente>{{ __('admin.boutiques.echeances.ouverture_attente', ['raison' => $blocageOuverture]) }}</x-admin.message>
            @else
                <x-admin.message type="info" class="mt-4 !mb-0" data-ouverture-prevue>{{ __('admin.boutiques.echeances.ouverture_prevue', ['date' => $campagne->date_ouverture->translatedFormat('l j F Y'), 'heure' => $campagne->date_ouverture->format('H:i')]) }}</x-admin.message>
                @if ($blocageOuverture)
                    <x-admin.message type="avertissement" class="mt-3 !mb-0" data-ouverture-a-regler>{{ __('admin.boutiques.echeances.a_regler', ['raison' => $blocageOuverture]) }}</x-admin.message>
                @endif
            @endif
        @elseif ($campagne->statut === StatutCampagneBoutiques::Ouverte)
            <x-admin.message type="info" class="mt-4 !mb-0" data-cloture-prevue>{{ __('admin.boutiques.echeances.cloture_prevue', ['ouverte' => ($campagne->ouverte_le ?? $campagne->date_ouverture)->format('d/m/Y H:i'), 'date' => $campagne->date_cloture->translatedFormat('l j F Y'), 'heure' => $campagne->date_cloture->format('H:i')]) }}</x-admin.message>
        @endif

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
                @if ($campagne->statut === StatutCampagneBoutiques::Ouverte)
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

    @php
        $f = __('admin.boutiques.fiche');
        $jours = max(1, (int) ceil($campagne->date_ouverture->diffInHours($campagne->date_cloture) / 24));
        $typesCampagne = $campagne->typesConcernes();
        $moyens = collect((array) $campagne->canaux_actifs)->map(fn ($code) => \App\Models\MoyenPaiement::libelleDe((string) $code));
    @endphp
    {{-- Campagne : période en tuiles lisibles, puis la présentation telle que la voient les citoyens. --}}
    <section class="bo-carte" aria-labelledby="sec-campagne">
        <h2 id="sec-campagne" class="!text-[1.05rem]">{{ __('admin.boutiques.sections.campagne') }}</h2>
        <p class="campagne-fiche-nom mt-2">{{ $campagne->nom }}</p>
        <dl class="campagne-fiche-periode mt-3" data-periode-campagne>
            <div>
                <dt>{{ $f['ouverture'] }}</dt>
                <dd>{{ $campagne->date_ouverture->translatedFormat('l j F Y') }}<span>{{ __('admin.boutiques.fiche.a_heure', ['h' => $campagne->date_ouverture->format('H:i')]) }}</span></dd>
            </div>
            <div>
                <dt>{{ $f['cloture'] }}</dt>
                <dd>{{ $campagne->date_cloture->translatedFormat('l j F Y') }}<span>{{ __('admin.boutiques.fiche.a_heure', ['h' => $campagne->date_cloture->format('H:i')]) }}</span></dd>
            </div>
            <div>
                <dt>{{ $f['duree'] }}</dt>
                <dd>{{ trans_choice('admin.boutiques.fiche.jours', $jours, ['n' => $jours]) }}</dd>
            </div>
        </dl>
        @if ($campagne->description)
            <div class="campagne-fiche-presentation mt-4" x-data="{ ouvert: false }" data-presentation-campagne>
                <button type="button" class="campagne-fiche-repli" aria-controls="presentation-campagne" aria-expanded="false" :aria-expanded="ouvert ? 'true' : 'false'" @click="ouvert = ! ouvert">
                    {{ $f['presentation'] }}
                    <span x-text="ouvert ? @js($f['masquer']) : @js($f['afficher'])">{{ $f['afficher'] }}</span>
                </button>
                <div id="presentation-campagne" class="texte-riche" x-show="ouvert" style="display: none">{{ \App\Support\MarkdownLeger::html($campagne->description) }}</div>
            </div>
        @else
            <p class="texte-carte mt-4">{{ $f['sans_presentation'] }}</p>
        @endif
    </section>

    {{-- Réservation et paiement : sites, types avec leur maximum par personne, moyens et délai de la caution. --}}
    <section class="bo-carte" aria-labelledby="sec-paiement">
        <h2 id="sec-paiement" class="!text-[1.05rem]">{{ __('admin.boutiques.sections.paiement') }}</h2>
        <div class="campagne-fiche-grille mt-3">
            <div>
                <h3 class="campagne-fiche-titre">{{ $f['sites'] }}</h3>
                <ul class="campagne-fiche-puces" data-sites-campagne>
                    @forelse ($campagne->sitesConcernes() as $site)
                        <li>@if ($site->code)<strong>{{ $site->code }}</strong> @endif{{ $site->nom }}</li>
                    @empty
                        <li>{{ $f['aucun'] }}</li>
                    @endforelse
                </ul>

                <h3 class="campagne-fiche-titre mt-5">{{ $f['moyens'] }}</h3>
                <ul class="campagne-fiche-puces">
                    @forelse ($moyens as $moyen)
                        <li>{{ $moyen }}</li>
                    @empty
                        <li>{{ $f['aucun'] }}</li>
                    @endforelse
                </ul>

                <h3 class="campagne-fiche-titre mt-5">{{ $f['delai'] }}</h3>
                <p class="campagne-fiche-delai" data-delai-caution>{{ $campagne->libelleDelaiPaiement() }}
                    <span class="texte-carte">({{ __($campagne->aSonPropreDelai() ? 'admin.boutiques.aides.delai_propre_court' : 'admin.boutiques.aides.delai_parametre_court') }})</span></p>

                {{-- Listes d'attente : correction de l'identité d'un attributaire, relance de la liste générale. --}}
                @foreach (['kyc', 'relance'] as $delai)
                    <h3 class="campagne-fiche-titre mt-5">{{ $f['delai_'.$delai] }}</h3>
                    <p class="campagne-fiche-delai" data-delai-liste="{{ $delai }}">{{ $campagne->libelleDelaiListe($delai) }}</p>
                @endforeach
            </div>
            <div>
                <h3 class="campagne-fiche-titre">{{ $f['types'] }}</h3>
                <div class="overflow-x-auto">
                    <table class="bo-tableau campagne-fiche-types" data-types-campagne data-limites-campagne>
                        <thead><tr><th scope="col">{{ $f['type'] }}</th><th scope="col" class="montant">{{ $f['maximum'] }}</th></tr></thead>
                        <tbody>
                            @forelse ($typesCampagne as $type)
                                <tr>
                                    <th scope="row">@if ($type->code)<span class="campagne-fiche-code">{{ $type->code }}</span> @endif{{ $type->libelle }}</th>
                                    <td class="montant">{{ $campagne->limitePour($type->id) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="2">{{ $f['aucun'] }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        @if ($sitesSansGrille && $campagne->statut !== StatutCampagneBoutiques::Confirmee)
            <x-admin.message type="avertissement" class="mt-4 !mb-0">{{ __('admin.boutiques.regles.sans_grille', ['lots' => implode(', ', $sitesSansGrille)]) }}</x-admin.message>
        @endif
    </section>

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

    {{-- Listes d'attente : inscrits en attente, liste générale (non attributaires), relances et boutiques restées libres. --}}
    @php($la = __('admin.boutiques.relances'))
    <section class="bo-carte" aria-labelledby="sec-listes" data-listes-attente>
        <h2 id="sec-listes" class="!text-[1.05rem]">{{ $la['titre'] }}</h2>
        <p class="texte-carte mt-1">{{ $la['intro'] }}</p>
        <div class="adm-cartes mt-3">
            <div class="adm-indicateur" data-en-attente><span class="adm-indicateur-libelle">{{ $la['en_attente'] }}</span><span class="adm-indicateur-valeur">{{ format_nombre($listes['en_attente']) }}</span></div>
            <div class="adm-indicateur" data-liste-generale><span class="adm-indicateur-libelle">{{ $la['generale'] }}</span><span class="adm-indicateur-valeur">{{ format_nombre($listes['generale']) }}</span></div>
        </div>
        @if ($listes['relances']->isEmpty())
            <p class="texte-carte mt-3">{{ $la['aucune'] }}</p>
        @else
            <div class="mt-3 overflow-x-auto">
                <table class="bo-tableau" data-relances>
                    <thead><tr>@foreach ($la['colonnes'] as $libelle)<th scope="col">{{ $libelle }}</th>@endforeach</tr></thead>
                    <tbody>
                        @foreach ($listes['relances'] as $rel)
                            <tr>
                                <td class="whitespace-nowrap">{{ $rel->ouverte_le->format('d/m/Y H:i') }}</td>
                                <td class="whitespace-nowrap">{{ $rel->fin_le->format('d/m/Y H:i') }}</td>
                                <td class="montant">{{ format_nombre(count($rel->boutiques)) }}</td>
                                <td class="montant">{{ format_nombre($rel->destinataires) }}</td>
                                <td>{{ $rel->cloturee_le ? (\App\Models\Boutique::query()->whereIn('id', (array) $rel->restantes)->orderBy('code')->pluck('code')->implode(', ') ?: $la['toutes_reprises']) : $la['en_cours'] }}</td>
                                <td>{{ $rel->regulateur_notifie_le ? $la['oui'] : '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
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
