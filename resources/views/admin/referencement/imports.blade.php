@php
    $i = __('admin.imports');
    $gerer = auth()->user()->can(\App\Enums\Droit::SitesGerer->value);
    $fichier = fn (string $nom, string $id) => '<input id="'.$id.'" name="'.$nom.'" type="file" required class="champ" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">';
@endphp

<x-admin.layout :titre="$i['titre']" :fil="[['libelle' => $i['titre']]]">
    <div class="bo-carte !mt-0">
        <p class="texte-carte max-w-3xl">{{ $i['intro'] }}</p>
    </div>

    @if ($gerer)
        <div class="mt-[18px] grid gap-[18px] xl:grid-cols-3">
            {{-- Sites et capacités prévues --}}
            <section class="bo-carte !mt-0" aria-labelledby="imp-sites">
                <h2 id="imp-sites" class="!text-base">{{ $i['sites_titre'] }}</h2>
                <p class="texte-carte">{{ $i['sites_aide'] }}</p>
                <a href="{{ route('admin.sites.excel.modele') }}" class="bo-bouton bo-bouton-secondaire mt-3">{{ __('admin.sites.import.modele') }}</a>
                <a href="{{ route('admin.sites.excel.export') }}" class="bo-bouton bo-bouton-secondaire mt-3">{{ __('admin.sites.import.exporter') }}</a>
                <form method="POST" action="{{ route('admin.sites.excel.deposer') }}" enctype="multipart/form-data" class="mt-3 space-y-3" novalidate>
                    @csrf
                    <div class="formulaire-champ !mt-0"><label for="fichier-sites">{{ __('admin.sites.import.importer') }}</label>{!! $fichier('fichier_sites', 'fichier-sites') !!}</div>
                    @error('fichier_sites') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
                    <button type="submit" class="bo-bouton bo-bouton-principal">{{ __('admin.sites.import.importer') }}</button>
                </form>
            </section>

            {{-- Boutiques d'un site --}}
            <section class="bo-carte !mt-0" aria-labelledby="imp-site">
                <h2 id="imp-site" class="!text-base">{{ $i['boutiques_site_titre'] }}</h2>
                <p class="texte-carte">{{ $i['boutiques_site_aide'] }}</p>
                @if ($sitesModifiables->isEmpty())
                    <p class="mt-3">{{ $i['aucun_site_modifiable'] }}</p>
                @else
                    <form method="POST" action="{{ route('admin.referencement.imports.boutiques-site') }}" enctype="multipart/form-data" class="mt-3 space-y-3" novalidate>
                        @csrf
                        <div class="formulaire-champ !mt-0">
                            <label for="site-import">{{ ucfirst($i['site']) }}</label>
                            <select id="site-import" name="site_id" class="champ" required>
                                <option value="">{{ $i['choisir_site'] }}</option>
                                @foreach ($sitesModifiables as $s)<option value="{{ $s->id }}">{{ $s->code }} · {{ $s->nom }}</option>@endforeach
                            </select>
                        </div>
                        <div class="formulaire-champ !mt-0"><label for="fichier-site">{{ __('admin.sites.excel.importer') }}</label>{!! $fichier('fichier', 'fichier-site') !!}</div>
                        @error('site_id') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
                        <button type="submit" class="bo-bouton bo-bouton-principal">{{ __('admin.sites.excel.importer') }}</button>
                    </form>
                @endif
            </section>

            {{-- Boutiques de plusieurs sites --}}
            <section class="bo-carte !mt-0" aria-labelledby="imp-combine">
                <h2 id="imp-combine" class="!text-base">{{ $i['combine_titre'] }}</h2>
                <p class="texte-carte">{{ $i['combine_aide'] }}</p>
                <a href="{{ route('admin.sites.boutiques-excel.modele') }}" class="bo-bouton bo-bouton-secondaire mt-3">{{ __('admin.sites.excel.modele') }}</a>
                <form method="POST" action="{{ route('admin.sites.boutiques-excel.deposer') }}" enctype="multipart/form-data" class="mt-3 space-y-3" novalidate>
                    @csrf
                    <div class="formulaire-champ !mt-0"><label for="fichier-combine">{{ __('admin.sites.excel.combine_importer') }}</label>{!! $fichier('fichier', 'fichier-combine') !!}</div>
                    @error('fichier') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
                    <button type="submit" class="bo-bouton bo-bouton-principal">{{ __('admin.sites.excel.combine_importer') }}</button>
                </form>
            </section>
        </div>
    @endif

    {{-- Journal de tous les imports --}}
    <section class="bo-carte" aria-labelledby="journal-titre">
        <h2 id="journal-titre" class="!text-[1.15rem]">{{ $i['journal_titre'] }}</h2>
        @if ($journal->isEmpty())
            <x-etat-vide icone="info" :titre="$i['vide_titre']">{{ $i['vide'] }}</x-etat-vide>
        @else
            <div class="mt-3 overflow-x-auto">
                <table class="bo-tableau">
                    <thead><tr>@foreach ($i['colonnes'] as $libelle)<th scope="col">{{ $libelle }}</th>@endforeach</tr></thead>
                    <tbody>
                        @foreach ($journal as $entree)
                            @php($v = $entree->nouvelle_valeur ?? [])
                            <tr>
                                <td class="whitespace-nowrap">{{ $entree->created_at->format('d/m/Y H:i') }}</td>
                                <td>{{ $entree->acteur?->name ?? '—' }}</td>
                                <td>{{ $i['types'][$entree->action] ?? $entree->action }}</td>
                                <td>{{ $entree->action === 'boutiques.importees' ? ($sites[$entree->entite_id] ?? '—') : $i['tous_sites'] }}</td>
                                <td>{{ $v['fichier'] ?? '—' }}<br>
                                    @if (! empty($v['chemin']))
                                        <a href="{{ route('admin.referencement.imports.fichier', $entree) }}" class="font-semibold text-vert-fonce underline underline-offset-2">{{ $i['telecharger'] }}</a>
                                    @else
                                        <span class="texte-carte">{{ $i['indisponible'] }}</span>
                                    @endif</td>
                                <td class="text-sm">{{ $entree->action === 'sites.importes'
                                    ? __('admin.imports.resultat_sites', ['crees' => $v['sites_crees'] ?? 0, 'maj' => $v['sites_mis_a_jour'] ?? 0, 'inchanges' => $v['sites_inchanges'] ?? 0, 'capacites' => $v['capacites'] ?? 0])
                                    : __('admin.imports.resultat_boutiques', ['crees' => $v['creees'] ?? 0, 'maj' => $v['mises_a_jour'] ?? 0, 'inchanges' => $v['inchangees'] ?? 0]) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $journal->links() }}</div>
        @endif
    </section>
</x-admin.layout>
