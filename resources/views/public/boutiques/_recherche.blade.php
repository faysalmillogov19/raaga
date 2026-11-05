{{--
    Recherche des boutiques (réunion du 05/11/2026), commune aux thèmes « lumière » et « nuit » : coût à l'entrée
    (caution + avance) minimum et maximum par zone, puis filtres budget (sur le total à l'entrée), zone, niveau, surface.
    Montants de la grille confirmée seulement ; nombre de personnes en liste d'attente, jamais de nom. « Montant de la
    1re tranche » : ligne prévue, affichée quand l'échéancier par tranches existera.
    Variables : $site, $type, $niveaux, $coutsParZone, $filtres, $resultats, $enAttente, $ouverte, $relance.
--}}
@php
    $r = __('public.boutiques.recherche');
    $statutsPlan = __('public.boutiques.plan.statuts');
    $nombre = fn (?string $cle) => isset($filtres[$cle]) ? (string) $filtres[$cle] : '';
@endphp

<section class="recherche-boutiques carte mt-6 p-5" aria-labelledby="recherche-titre" id="recherche" data-recherche>
    <h2 id="recherche-titre" class="!text-xl">{{ $r['titre'] }}</h2>

    @if ($coutsParZone->isNotEmpty())
        <div class="recherche-tableau mt-3" data-couts-zones>
            <table>
                <caption class="texte-carte">{{ $r['couts_legende'] }}</caption>
                <thead><tr><th scope="col">{{ $r['zone'] }}</th><th scope="col">{{ $r['boutiques'] }}</th><th scope="col">{{ $r['minimum'] }}</th><th scope="col">{{ $r['maximum'] }}</th></tr></thead>
                <tbody>
                    @foreach ($coutsParZone as $zone => $c)
                        <tr data-zone="{{ $zone }}"><th scope="row">{{ $zone }}</th><td>{{ $c['nombre'] }}</td><td>{{ format_fcfa($c['min']) }}</td><td>{{ format_fcfa($c['max']) }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <form method="GET" action="{{ route('boutiques') }}#recherche" class="recherche-filtres mt-4" data-filtres-recherche>
        @if ($site)<input type="hidden" name="site" value="{{ $site->id }}">@endif
        @if ($type)<input type="hidden" name="type" value="{{ $type->id }}">@endif
        <fieldset class="recherche-groupe">
            <legend class="font-semibold text-encre">{{ $r['budget'] }}</legend>
            <label for="budget_min" class="sr-only">{{ $r['budget_min'] }}</label>
            <input id="budget_min" name="budget_min" type="text" inputmode="numeric" class="champ" placeholder="{{ $r['budget_min'] }}" value="{{ $nombre('budget_min') }}">
            <label for="budget_max" class="sr-only">{{ $r['budget_max'] }}</label>
            <input id="budget_max" name="budget_max" type="text" inputmode="numeric" class="champ" placeholder="{{ $r['budget_max'] }}" value="{{ $nombre('budget_max') }}">
        </fieldset>
        <div class="recherche-groupe">
            <label for="zone" class="font-semibold text-encre">{{ $r['zone'] }}</label>
            <select id="zone" name="zone" class="champ">
                <option value="">{{ $r['toutes_zones'] }}</option>
                @foreach ($coutsParZone->keys() as $zone)
                    <option value="{{ $zone }}" @selected(($filtres['zone'] ?? null) === (string) $zone)>{{ $zone }}</option>
                @endforeach
            </select>
        </div>
        <div class="recherche-groupe">
            <label for="niveau_recherche" class="font-semibold text-encre">{{ $r['niveau'] }}</label>
            <select id="niveau_recherche" name="niveau_recherche" class="champ">
                <option value="">{{ $r['tous_niveaux'] }}</option>
                @foreach ($niveaux->keys() as $n)
                    <option value="{{ $n }}" @selected(($filtres['niveau'] ?? null) === (string) $n)>{{ $n }}</option>
                @endforeach
            </select>
        </div>
        <fieldset class="recherche-groupe">
            <legend class="font-semibold text-encre">{{ $r['surface'] }}</legend>
            <label for="surface_min" class="sr-only">{{ $r['surface_min'] }}</label>
            <input id="surface_min" name="surface_min" type="text" inputmode="decimal" class="champ" placeholder="{{ $r['surface_min'] }}" value="{{ $nombre('surface_min') }}">
            <label for="surface_max" class="sr-only">{{ $r['surface_max'] }}</label>
            <input id="surface_max" name="surface_max" type="text" inputmode="decimal" class="champ" placeholder="{{ $r['surface_max'] }}" value="{{ $nombre('surface_max') }}">
        </fieldset>
        <div class="recherche-actions">
            <button type="submit" class="btn-primaire">{{ $r['rechercher'] }}</button>
            @if ($filtres !== [])
                <a href="{{ route('boutiques', array_filter(['site' => $site?->id, 'type' => $type?->id])) }}#recherche" class="lien">{{ $r['effacer'] }}</a>
            @endif
        </div>
    </form>

    @if ($resultats !== null)
        <p class="mt-4 font-semibold text-encre" role="status" data-nombre-resultats>{{ trans_choice('public.boutiques.recherche.trouvees', $resultats->count(), ['n' => $resultats->count()]) }}</p>
        @if ($resultats->count() > \App\Http\Controllers\Compte\BoutiquesController::RESULTATS_AFFICHES)
            <p class="texte-carte">{{ __('public.boutiques.recherche.premiers', ['n' => \App\Http\Controllers\Compte\BoutiquesController::RESULTATS_AFFICHES]) }}</p>
        @endif
        <ul class="recherche-resultats mt-3" data-resultats>
            @foreach ($resultats->take(\App\Http\Controllers\Compte\BoutiquesController::RESULTATS_AFFICHES) as $boutique)
                @php
                    $d = \App\Services\Boutiques\ReservationsBoutiques::detail($boutique);
                    $statut = $boutique->statut->surPlan();
                    $lienBoutique = in_array($statut, ['disponible', 'pre_reservee', 'reservee'], true) && ($ouverte || in_array($boutique->id, $relance, true)) ? route('boutiques.boutique', $boutique->code) : null;
                    $attente = $enAttente[$boutique->id] ?? 0;
                @endphp
                <li class="recherche-resultat" data-resultat="{{ $boutique->code }}">
                    <p class="recherche-entete"><strong class="plan-code">{{ $boutique->code }}</strong><span class="plan-sigle">{{ $boutique->type?->code }}</span><span class="texte-carte">{{ $statutsPlan[$statut] ?? $statut }}</span></p>
                    <p class="texte-carte">{{ __('public.boutiques.recherche.emplacement', ['zone' => $boutique->bloc, 'niveau' => $boutique->niveau, 'surface' => $d['surface'] ?? $r['surface_inconnue']]) }}</p>
                    <dl class="recherche-montants">
                        <div data-total-entree><dt>{{ $r['total_entree'] }}</dt><dd>{{ $d['montants']['total'] }}</dd></div>
                        <div data-loyer><dt>{{ $r['loyer'] }}</dt><dd>{{ $d['montants']['loyer'] }}</dd></div>
                        @if ($d['montants']['premiere_tranche'])
                            <div data-premiere-tranche><dt>{{ $r['premiere_tranche'] }}</dt><dd>{{ $d['montants']['premiere_tranche'] }}</dd></div>
                        @endif
                    </dl>
                    <p class="texte-carte" data-liste-attente-nombre>{{ trans_choice('public.boutiques.recherche.attente', $attente, ['n' => $attente]) }}</p>
                    @if ($lienBoutique)
                        <a href="{{ $lienBoutique }}" class="lien mt-2">{{ $statut === 'disponible' ? __('public.boutiques.plan.detail.reserver') : __('public.boutiques.plan.detail.inscrire') }}</a>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</section>
