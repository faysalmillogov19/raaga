@php
    use App\Enums\Droit;
    use App\Enums\StatutSite;

    $gerer = auth()->user()->can(Droit::SitesGerer->value);
    $modifiable = $gerer && $site->estModifiable();
    $b = __('admin.sites.boutiques');
    $libelles = __('admin.sites.champs');
@endphp

<x-admin.layout :titre="$site->nom" :fil="[['libelle' => __('admin.sites.titre'), 'url' => route('admin.sites')], ['libelle' => $site->nom]]">
    {{-- Statut et actions --}}
    <section class="bo-carte !mt-0" aria-labelledby="etat-titre">
        <h2 id="etat-titre" class="!text-[1.15rem]">{{ __('admin.parametrage.statut') }} : @include('admin.parametrage._statut', ['statut' => $site->statut])</h2>
        <p class="texte-carte mt-1">{{ $site->region }} · {{ $site->commune }} — {{ $site->adresse }}</p>
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

        @if ($modifiable)
            @if ($types->isEmpty())
                <p class="bo-message bo-message-erreur mt-4">{{ __('admin.sites.types.aucun') }} <a href="{{ route('admin.sites') }}">{{ __('admin.sites.types.titre') }}</a></p>
            @else
                <div class="mt-4 grid gap-[18px] xl:grid-cols-2">
                    <form method="POST" action="{{ route('admin.sites.boutiques.serie', $site) }}" class="rounded-charte border border-ligne p-4" novalidate>
                        @csrf
                        <h3 class="!text-base">{{ $b['serie_titre'] }}</h3>
                        <p class="texte-carte">{{ $b['serie_aide'] }}</p>
                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            <x-admin.champ nom="prefixe" :libelle="$b['prefixe']" maxlength="20" />
                            <x-admin.champ nom="chiffres" :libelle="$b['chiffres']" type="number" min="1" max="5" valeur="3" required />
                            <x-admin.champ nom="debut" :libelle="$b['debut']" type="number" min="0" valeur="1" required />
                            <x-admin.champ nom="fin" :libelle="$b['fin']" type="number" min="0" required />
                            <x-admin.champ nom="bloc" :libelle="$b['bloc']" maxlength="40" />
                            <x-admin.champ nom="surface" :libelle="$b['surface']" type="number" step="0.01" min="0.5" />
                            <div class="formulaire-champ !mt-0 sm:col-span-2">
                                <label for="serie-type">{{ $b['type'] }}</label>
                                <select id="serie-type" name="type_boutique_id" class="champ" required>
                                    @foreach ($types as $type)<option value="{{ $type->id }}">{{ $type->libelle }}</option>@endforeach
                                </select>
                            </div>
                        </div>
                        <button type="submit" class="bo-bouton bo-bouton-principal mt-3">{{ $b['generer'] }}</button>
                    </form>

                    <form method="POST" action="{{ route('admin.sites.boutiques.store', $site) }}" class="rounded-charte border border-ligne p-4" novalidate>
                        @csrf
                        <h3 class="!text-base">{{ $b['ajouter_titre'] }}</h3>
                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            <x-admin.champ nom="numero" :libelle="$b['numero']" maxlength="30" required />
                            <div class="formulaire-champ !mt-0"><label for="unique-bloc">{{ $b['bloc'] }}</label>
                                <input id="unique-bloc" name="bloc" maxlength="40" class="champ"></div>
                            <div class="formulaire-champ !mt-0"><label for="unique-surface">{{ $b['surface'] }}</label>
                                <input id="unique-surface" name="surface" type="number" step="0.01" min="0.5" class="champ"></div>
                            <div class="formulaire-champ !mt-0">
                                <label for="unique-type">{{ $b['type'] }}</label>
                                <select id="unique-type" name="type_boutique_id" class="champ" required>
                                    @foreach ($types as $type)<option value="{{ $type->id }}">{{ $type->libelle }}</option>@endforeach
                                </select>
                            </div>
                        </div>
                        <button type="submit" class="bo-bouton bo-bouton-principal mt-3">{{ $b['ajouter'] }}</button>
                    </form>
                </div>
            @endif
        @endif

        <div class="mt-4 overflow-x-auto">
            <table class="bo-tableau">
                <thead><tr>
                    <th scope="col">{{ $b['numero'] }}</th><th scope="col">{{ $b['bloc'] }}</th><th scope="col" class="montant">{{ $b['surface'] }}</th>
                    <th scope="col">{{ $b['type'] }}</th><th scope="col">{{ $b['statut'] }}</th>@if ($modifiable)<th scope="col"><span class="sr-only">{{ $b['supprimer'] }}</span></th>@endif
                </tr></thead>
                <tbody>
                    @forelse ($boutiques as $boutique)
                        <tr>
                            <td class="font-mono font-semibold">{{ $boutique->numero }}</td>
                            <td>{{ $boutique->bloc ?: '—' }}</td>
                            <td class="montant">{{ $boutique->surface !== null ? format_nombre((float) $boutique->surface, 2) : '—' }}</td>
                            <td>{{ $boutique->type->libelle }}</td>
                            <td>{{ $boutique->statut->label() }}</td>
                            @if ($modifiable)
                                <td>@unless ($boutique->statut->verrouillee())
                                    <form method="POST" action="{{ route('admin.sites.boutiques.destroy', [$site, $boutique]) }}">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="bo-bouton bo-bouton-secondaire">{{ $b['supprimer'] }}</button>
                                    </form>
                                @endunless</td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="6">{{ $b['aucune'] }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    @include('admin.parametrage._historique', ['versions' => $versions, 'decisions' => $decisions])
</x-admin.layout>
