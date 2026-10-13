@php
    use App\Enums\Droit;
    use App\Enums\StatutSite;

    $gerer = auth()->user()->can(Droit::SitesGerer->value);
    $modifiable = $gerer && $site->estModifiable();
    $b = __('admin.sites.boutiques');
    $libelles = __('admin.sites.champs');
    $numeroMax = str_repeat('9', \App\Models\Boutique::CHIFFRES_NUMERO);
@endphp

<x-admin.layout :titre="$site->nom" :fil="[['libelle' => __('admin.sites.titre'), 'url' => route('admin.sites')], ['libelle' => $site->nom]]">
    {{-- Statut et actions --}}
    <section class="bo-carte !mt-0" aria-labelledby="etat-titre">
        <h2 id="etat-titre" class="!text-[1.15rem]">{{ __('admin.parametrage.statut') }} : @include('admin.parametrage._statut', ['statut' => $site->statut])</h2>
        <p class="texte-carte mt-1">@if ($site->code)<span class="font-mono font-bold text-encre">{{ $site->code }}</span> · @endif{{ $site->region }} · {{ $site->commune }} — {{ $site->adresse }}</p>
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

    {{-- Boutiques du site --}}
    <section class="bo-carte" aria-labelledby="boutiques-titre">
        <h2 id="boutiques-titre" class="!text-[1.15rem]">{{ $b['titre'] }} ({{ format_nombre($boutiques->count()) }})</h2>
        @if ($boutiques->isNotEmpty())
            <p class="texte-carte mt-1">{{ $boutiques->groupBy(fn ($x) => $x->type->libelle)->map(fn ($g, $t) => __('admin.sites.boutiques.par_type', ['type' => $t, 'n' => $g->count()]))->implode(' · ') }}</p>
        @endif
        @if ($gerer && ! $modifiable)
            <p class="texte-carte mt-1">{{ $b['modifiable_brouillon'] }}</p>
        @endif

        @if (($sansNiveau = $boutiques->whereNull('niveau')->where('statut', '!=', \App\Enums\StatutBoutique::Retiree)->count()) > 0)
            <p class="bo-message bo-message-erreur mt-4 !mb-0" role="status">{{ trans_choice('admin.sites.boutiques.sans_niveau_alerte', $sansNiveau, ['n' => $sansNiveau]) }}</p>
        @endif
        @if ($gerer && $site->code === null)
            <p class="bo-message bo-message-erreur mt-4 !mb-0" role="status">{{ __('admin.sites.regles.sans_code_site') }}</p>
        @endif

        @if ($modifiable && $site->code !== null)
            @if ($types->isEmpty())
                <p class="bo-message bo-message-erreur mt-4">{{ __('admin.sites.types.aucun') }} <a href="{{ route('admin.sites') }}">{{ __('admin.sites.types.titre') }}</a></p>
            @else
                <div class="mt-4 grid gap-[18px] xl:grid-cols-2">
                    <form method="POST" action="{{ route('admin.sites.boutiques.serie', $site) }}" class="rounded-charte border border-ligne p-4" novalidate>
                        @csrf
                        <h3 class="!text-base">{{ $b['serie_titre'] }}</h3>
                        <p class="texte-carte">{{ __('admin.sites.boutiques.serie_aide', ['site' => $site->code]) }}</p>
                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            @include('admin.sites._champs-code', ['prefixe' => 'serie', 'boutique' => null])
                            <x-admin.champ nom="debut" :libelle="$b['debut']" type="number" min="1" max="{{ $numeroMax }}" valeur="1" required />
                            <x-admin.champ nom="fin" :libelle="$b['fin']" type="number" min="1" max="{{ $numeroMax }}" required />
                            @include('admin.sites._champs-fiche', ['prefixe' => 'serie', 'boutique' => null])
                        </div>
                        <button type="submit" class="bo-bouton bo-bouton-principal mt-3">{{ $b['generer'] }}</button>
                    </form>

                    <form method="POST" action="{{ route('admin.sites.boutiques.store', $site) }}" class="rounded-charte border border-ligne p-4" novalidate>
                        @csrf
                        <h3 class="!text-base">{{ $b['ajouter_titre'] }}</h3>
                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            @include('admin.sites._champs-code', ['prefixe' => 'unique', 'boutique' => null])
                            <div class="formulaire-champ !mt-0"><label for="unique-numero">{{ $b['numero'] }}</label>
                                <input id="unique-numero" name="numero" type="number" min="1" max="{{ $numeroMax }}" required class="champ" aria-describedby="unique-numero-aide">
                                <p id="unique-numero-aide" class="aide-champ">{{ $b['numero_aide'] }}</p></div>
                            @include('admin.sites._champs-fiche', ['prefixe' => 'unique', 'boutique' => null])
                        </div>
                        <button type="submit" class="bo-bouton bo-bouton-principal mt-3">{{ $b['ajouter'] }}</button>
                    </form>
                </div>
            @endif
        @endif

        <div class="mt-4 overflow-x-auto">
            <table class="bo-tableau">
                <thead><tr>
                    <th scope="col">{{ $b['code'] }}</th><th scope="col">{{ $b['niveau'] }}</th><th scope="col">{{ $b['bloc'] }}</th>
                    <th scope="col">{{ $b['type'] }}</th><th scope="col" class="montant">{{ $b['surface'] }}</th><th scope="col">{{ $b['position'] }}</th>
                    <th scope="col">{{ $b['coffret'] }}</th><th scope="col">{{ $b['planche'] }}</th>
                    <th scope="col">{{ $b['etat_physique'] }}</th><th scope="col">{{ $b['statut'] }}</th>
                    @if ($modifiable)<th scope="col"><span class="sr-only">{{ $b['modifier'] }}</span></th>@endif
                </tr></thead>
                <tbody>
                    @forelse ($boutiques as $boutique)
                        <tr>
                            <td class="whitespace-nowrap font-mono font-semibold">{{ $boutique->reference() }}
                                @if ($boutique->code === null)<br><span class="text-sm font-normal text-gris">{{ __('admin.sites.boutiques.ancien_numero', ['numero' => $boutique->numero]) }}</span>@endif</td>
                            <td>@if ($boutique->niveau){{ $boutique->niveau->label() }}@else<span class="font-semibold text-rouge">{{ $b['sans_niveau'] }}</span>@endif</td>
                            <td>{{ $boutique->bloc ?: '—' }}</td>
                            <td>{{ $boutique->type->libelle }}</td>
                            <td class="montant">{{ $boutique->surface !== null ? format_nombre((float) $boutique->surface, 2) : '—' }}</td>
                            <td>{{ $boutique->position?->label() ?? '—' }}</td>
                            <td>{{ $boutique->coffret ?: '—' }}</td>
                            <td>{{ $boutique->planche ?: '—' }}</td>
                            <td>{{ $boutique->etat_physique?->label() ?? $b['non_renseigne'] }}</td>
                            <td>{{ $boutique->statut->label() }}</td>
                            @if ($modifiable)
                                <td>@unless ($boutique->statut->verrouillee())
                                    <div class="flex gap-2">
                                        <a href="{{ route('admin.sites.boutiques.edit', [$site, $boutique]) }}" class="bo-bouton bo-bouton-secondaire">{{ $b['modifier'] }}</a>
                                        <form method="POST" action="{{ route('admin.sites.boutiques.destroy', [$site, $boutique]) }}">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="bo-bouton bo-bouton-secondaire">{{ $b['supprimer'] }}</button>
                                        </form>
                                    </div>
                                @endunless</td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="11">{{ $b['aucune'] }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    @include('admin.parametrage._historique', ['versions' => $versions, 'decisions' => $decisions])
</x-admin.layout>
