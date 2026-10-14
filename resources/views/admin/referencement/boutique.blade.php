@php
    use App\Enums\Droit;

    $b = __('admin.referencement.boutiques');
    $f = __('admin.sites.boutiques');
    $modifiable = auth()->user()->can(Droit::SitesGerer->value) && $boutique->site->estModifiable() && ! $boutique->statut->verrouillee();
    $lignes = [
        $f['code'] => $boutique->reference(), $b['site'] => ($boutique->site->code ? $boutique->site->code.' · ' : '').$boutique->site->nom,
        $f['niveau'] => $boutique->libelleNiveau(), $f['bloc'] => $boutique->bloc, $f['numero'] => $boutique->numero,
        $f['type'] => $boutique->type->libelle, $f['surface'] => $boutique->surface !== null ? format_nombre((float) $boutique->surface, 2) : null,
        $f['position'] => $boutique->position?->label(), $f['coffret'] => $boutique->coffret, $f['planche'] => $boutique->planche,
        $f['etat_physique'] => $boutique->etat_physique?->label(), $f['statut'] => $boutique->statut->label(), $f['observations'] => $boutique->observations,
    ];
@endphp

<x-admin.layout :titre="$b['fiche'].' '.$boutique->reference()" :fil="[['libelle' => $b['titre'], 'url' => route('admin.referencement.boutiques')], ['libelle' => $boutique->reference()]]">
    <section class="bo-carte !mt-0" aria-labelledby="fiche-titre">
        <h2 id="fiche-titre" class="!text-[1.15rem]">{{ $b['fiche'] }}</h2>
        <dl class="mt-3 grid gap-x-6 gap-y-2 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($lignes as $libelle => $valeur)
                <div><dt class="texte-carte">{{ $libelle }}</dt><dd class="font-semibold text-encre">{{ $valeur ?? '—' }}</dd></div>
            @endforeach
        </dl>
        <div class="mt-4 flex flex-wrap gap-3">
            <a href="{{ route('admin.sites.show', $boutique->site) }}" class="bo-bouton bo-bouton-secondaire">{{ $b['voir_site'] }}</a>
            @if ($modifiable)
                <a href="{{ route('admin.sites.boutiques.edit', [$boutique->site, $boutique]) }}" class="bo-bouton bo-bouton-principal">{{ $b['modifier'] }}</a>
            @endif
        </div>
    </section>

    <section class="bo-carte" aria-labelledby="historique-titre">
        <h2 id="historique-titre" class="!text-[1.15rem]">{{ $b['historique'] }}</h2>
        @if ($anciensCodes->isNotEmpty())
            <h3 class="mt-3 !text-base">{{ $b['anciens_codes'] }}</h3>
            <ul class="mt-1 space-y-1">
                @foreach ($anciensCodes as $h)
                    <li class="font-mono">{{ __('admin.referencement.boutiques.ancien_code', ['ancien' => $h->ancien_code, 'nouveau' => $h->nouveau_code, 'date' => $h->created_at->format('d/m/Y')]) }}</li>
                @endforeach
            </ul>
        @endif
        @if ($journal->isEmpty())
            <p class="texte-carte mt-2">{{ $b['aucun_historique'] }}</p>
        @else
            <div class="mt-3 overflow-x-auto">
                <table class="bo-tableau">
                    <thead><tr><th scope="col">{{ $b['date'] }}</th><th scope="col">{{ $b['auteur'] }}</th><th scope="col">{{ $b['action'] }}</th></tr></thead>
                    <tbody>
                        @foreach ($journal as $entree)
                            <tr><td class="whitespace-nowrap">{{ $entree->created_at->format('d/m/Y H:i') }}</td><td>{{ $entree->acteur?->name ?? '—' }}</td>
                                <td class="font-mono text-sm">{{ $entree->action }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</x-admin.layout>
