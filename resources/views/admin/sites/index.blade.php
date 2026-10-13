@php
    $gerer = auth()->user()->can(\App\Enums\Droit::SitesGerer->value);
@endphp

<x-admin.layout :titre="__('admin.sites.titre')" :fil="[['libelle' => __('admin.sites.titre')]]">
    <div class="bo-carte">
        <div class="mb-4 flex flex-wrap items-start justify-between gap-4">
            <p class="texte-carte max-w-3xl">{{ __('admin.sites.intro') }}</p>
            @if ($gerer)
                <a href="{{ route('admin.sites.create') }}" class="bo-bouton bo-bouton-principal">{{ __('admin.sites.nouveau') }}</a>
            @endif
        </div>

        <div class="overflow-x-auto">
            <table class="bo-tableau">
                <thead><tr>
                    @foreach (__('admin.sites.colonnes') as $cle => $libelle)
                        <th scope="col" @class(['montant' => $cle === 'boutiques'])>{{ $libelle }}</th>
                    @endforeach
                </tr></thead>
                <tbody>
                    @forelse ($sites as $site)
                        <tr>
                            <td class="font-mono font-semibold">{{ $site->code ?? '—' }}</td>
                            <td><a href="{{ route('admin.sites.show', $site) }}" class="font-bold text-vert-fonce underline underline-offset-2">{{ $site->nom }}</a></td>
                            <td>{{ $site->localite() ?? '—' }}@if ($site->arrondissement) · {{ $site->arrondissement }}@endif</td>
                            <td>@include('admin.parametrage._statut', ['statut' => $site->statut])</td>
                            <td class="montant">{{ format_nombre($site->boutiques_count) }}
                                @if ($site->boutiques_sans_niveau_count > 0)<br><span class="text-sm font-semibold text-rouge">{{ trans_choice('admin.sites.boutiques.sans_niveau_alerte', $site->boutiques_sans_niveau_count, ['n' => $site->boutiques_sans_niveau_count]) }}</span>@endif</td>
                            <td>{{ $site->version ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6">{{ __('admin.sites.aucun') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($gerer)
            {{-- Sites et capacités prévues en Excel : modèle, import avec aperçu, export --}}
            @php
                $x = __('admin.sites.import');
                $imports = \App\Http\Controllers\Admin\ImportSitesController::journal();
            @endphp
            <div class="mt-5 rounded-charte border border-ligne p-4">
                <h2 class="!text-base">{{ $x['titre'] }}</h2>
                <p class="texte-carte">{{ __('admin.sites.import.aide', ['taille' => format_nombre(config('raaga.referentiel_sites.taille_max_ko') / 1024)]) }}</p>
                <div class="mt-3 flex flex-wrap items-end gap-3">
                    <a href="{{ route('admin.sites.excel.modele') }}" class="bo-bouton bo-bouton-secondaire">{{ $x['modele'] }}</a>
                    <a href="{{ route('admin.sites.excel.export') }}" class="bo-bouton bo-bouton-secondaire">{{ $x['exporter'] }}</a>
                    <form method="POST" action="{{ route('admin.sites.excel.deposer') }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-3" novalidate>
                        @csrf
                        <div class="formulaire-champ !mt-0">
                            <label for="fichier-sites">{{ $x['importer'] }}</label>
                            <input id="fichier-sites" name="fichier_sites" type="file" required class="champ @error('fichier_sites') est-invalide @enderror"
                                   accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">
                        </div>
                        <button type="submit" class="bo-bouton bo-bouton-principal">{{ $x['importer'] }}</button>
                    </form>
                </div>
                @error('fichier_sites') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror

                {{-- Boutiques de plusieurs sites existants en un seul fichier (ne crée jamais de site). --}}
                <h3 class="mt-5 !text-[0.95rem]">{{ __('admin.sites.excel.combine_titre') }}</h3>
                <p class="texte-carte">{{ __('admin.sites.excel.combine_aide') }}</p>
                <div class="mt-3 flex flex-wrap items-end gap-3">
                    <a href="{{ route('admin.sites.boutiques-excel.modele') }}" class="bo-bouton bo-bouton-secondaire">{{ __('admin.sites.excel.modele') }}</a>
                    <form method="POST" action="{{ route('admin.sites.boutiques-excel.deposer') }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-3" novalidate>
                        @csrf
                        <div class="formulaire-champ !mt-0">
                            <label for="fichier-boutiques">{{ __('admin.sites.excel.combine_importer') }}</label>
                            <input id="fichier-boutiques" name="fichier" type="file" required class="champ @error('fichier') est-invalide @enderror"
                                   accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">
                        </div>
                        <button type="submit" class="bo-bouton bo-bouton-principal">{{ __('admin.sites.excel.combine_importer') }}</button>
                    </form>
                </div>
                @error('fichier') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror

                @if ($imports->isNotEmpty())
                    <h3 class="mt-4 !text-[0.95rem]">{{ $x['journal_titre'] }}</h3>
                    <ul class="texte-carte mt-1 space-y-1">
                        @foreach ($imports as $import)
                            <li>{{ __('admin.sites.import.journal_ligne', [
                                'date' => $import->created_at->format('d/m/Y H:i'), 'auteur' => $import->acteur?->name ?? '—',
                                'fichier' => $import->nouvelle_valeur['fichier'] ?? '', 'empreinte' => substr($import->nouvelle_valeur['empreinte'] ?? '', 0, 12),
                                'sites_crees' => $import->nouvelle_valeur['sites_crees'] ?? 0, 'sites_mis_a_jour' => $import->nouvelle_valeur['sites_mis_a_jour'] ?? 0,
                                'capacites' => $import->nouvelle_valeur['capacites'] ?? 0,
                            ]) }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif
    </div>

    {{-- Types de boutiques : catégories communes à tous les sites --}}
    <section class="bo-carte" aria-labelledby="types-titre">
        <h2 id="types-titre" class="!text-[1.15rem]">{{ __('admin.sites.types.titre') }}</h2>
        <p class="texte-carte mt-1">{{ __('admin.sites.types.aide') }}</p>

        @if ($types->isEmpty())
            <p class="mt-3">{{ __('admin.sites.types.aucun') }}</p>
        @else
            <ul class="mt-3 divide-y divide-ligne">
                @foreach ($types as $type)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-2">
                        <span>@if ($type->code)<span class="font-mono font-semibold text-vert-fonce">{{ $type->code }}</span> · @endif<span class="font-semibold text-encre">{{ $type->libelle }}</span>
                            @unless ($type->code)<span class="text-sm font-semibold text-rouge">({{ __('admin.sites.types.code_manquant') }})</span>@endunless
                            @if ($type->description) <span class="texte-carte">— {{ $type->description }}</span>@endif
                            <span class="texte-carte">· {{ __('admin.sites.types.nombre', ['n' => format_nombre($type->boutiques_count)]) }}</span></span>
                        @if ($gerer && ! $type->code)
                            <form method="POST" action="{{ route('admin.sites.types.update', $type) }}" class="flex items-end gap-2" novalidate>
                                @csrf @method('PATCH')
                                <label for="type-code-{{ $type->id }}" class="sr-only">{{ __('admin.sites.types.code') }}</label>
                                <input id="type-code-{{ $type->id }}" name="code" maxlength="6" required class="champ !w-28 font-mono uppercase" placeholder="{{ __('admin.sites.types.code') }}">
                                <button type="submit" class="bo-bouton bo-bouton-secondaire">{{ __('admin.sites.types.enregistrer_code') }}</button>
                            </form>
                        @endif
                        @if ($gerer && $type->boutiques_count === 0)
                            <form method="POST" action="{{ route('admin.sites.types.destroy', $type) }}">
                                @csrf @method('DELETE')
                                <button type="submit" class="bo-bouton bo-bouton-secondaire">{{ __('admin.sites.types.supprimer') }}</button>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($gerer)
            <form method="POST" action="{{ route('admin.sites.types.store') }}" class="mt-4 grid gap-3 lg:grid-cols-[10rem_1fr_2fr_auto] lg:items-end" novalidate>
                @csrf
                <x-admin.champ nom="code" :libelle="__('admin.sites.types.code')" maxlength="6" required :aide="__('admin.sites.types.code_aide')" />
                <x-admin.champ nom="libelle" :libelle="__('admin.sites.types.libelle')" maxlength="80" required />
                <x-admin.champ nom="description" :libelle="__('admin.sites.types.description')" maxlength="300" />
                <button type="submit" class="bo-bouton bo-bouton-principal">{{ __('admin.sites.types.ajouter') }}</button>
            </form>
        @endif
    </section>
</x-admin.layout>
