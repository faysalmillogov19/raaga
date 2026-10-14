{{-- Fiche du site : localisation, projet, plans et sources. Aucune donnée financière (section à part, hors GESTIONNAIRE). --}}
@php
    $c = __('admin.sites.champs');
    $vide = __('admin.sites.fiche.non_renseigne');
    $lignes = [
        'ville' => $site->localite(), 'arrondissement' => $site->arrondissement, 'region' => $site->region, 'adresse' => $site->adresse,
        'latitude' => $site->latitude !== null ? $site->latitude.' · '.$site->longitude : null,
        'maitre_ouvrage' => $site->maitre_ouvrage, 'maitre_oeuvre' => $site->maitre_oeuvre, 'phase' => $site->phase?->label(),
        'livraison_prevue' => $site->livraison_prevue?->translatedFormat('F Y'),
        'reference_plans' => $site->reference_plans, 'version_plans' => $site->version_plans,
        'source_donnees' => $site->source_donnees,
    ];
@endphp
<section class="bo-carte" aria-labelledby="fiche-titre">
    <h2 id="fiche-titre" class="!text-[1.15rem]">{{ __('admin.sites.fiche.titre') }}</h2>
    <dl class="mt-3 grid gap-x-6 gap-y-2 sm:grid-cols-2 xl:grid-cols-3">
        @foreach ($lignes as $cle => $valeur)
            @php($cleLibelle = $cle === 'latitude' ? 'latitude' : $cle)
            <div>
                <dt class="texte-carte">{{ ucfirst($cle === 'latitude' ? $c['latitude'].' · '.$c['longitude'] : $c[$cleLibelle]) }}</dt>
                <dd class="font-semibold text-encre">{{ $valeur ?? $vide }}</dd>
            </div>
        @endforeach
    </dl>
</section>
