@php
    use App\Enums\Droit;

    $b = __('admin.referencement.boutiques');
    $x = __('admin.sites.excel');
    $gerer = auth()->user()->can(Droit::SitesGerer->value);
    $siteFiltre = (int) ($tableau->etat['filtres']['site'] ?? 0);
@endphp

<x-admin.layout :titre="$b['titre']" :fil="[['libelle' => $b['titre']]]">
    <div class="bo-carte !mt-0">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <p class="texte-carte max-w-3xl">{{ $b['intro'] }}</p>
            @if ($gerer)
                <a href="{{ route('admin.referencement.boutiques.ajouter', $sitesModifiables->contains('id', $siteFiltre) ? ['site' => $siteFiltre] : []) }}" class="bo-bouton bo-bouton-principal">{{ $b['ajouter'] }}</a>
            @endif
        </div>

        <x-tableau :t="$tableau" :libelle="$b['titre']" class="mt-4" />
    </div>

    {{-- Fichier Excel : site choisi d'abord ; modèle et import pour un site en brouillon ou en révision, export pour tout site. --}}
    @if ($gerer)
        <section class="bo-carte" aria-labelledby="excel-titre">
            <h2 id="excel-titre" class="!text-[1.15rem]">{{ $x['titre'] }}</h2>
            <p class="texte-carte mt-1 max-w-3xl">{{ __('admin.sites.excel.aide', ['taille' => format_nombre(config('raaga.referentiel_boutiques.taille_max_ko') / 1024)]) }}</p>
            <div class="mt-3 grid gap-[18px] xl:grid-cols-2">
                <form method="POST" action="{{ route('admin.referencement.boutiques.excel.deposer') }}" enctype="multipart/form-data" class="rounded-charte border border-ligne p-4" novalidate>
                    @csrf
                    <p class="texte-carte">{{ $b['excel_import_aide'] }}</p>
                    @if ($sitesModifiables->isEmpty())
                        <p class="bo-message bo-message-erreur mt-3 !mb-0" role="status">{{ $b['aucun_site_modifiable'] }}</p>
                    @else
                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            <div class="formulaire-champ !mt-0">
                                <label for="import-site">{{ $b['choisir_site'] }}</label>
                                <select id="import-site" name="site_id" class="champ @error('site_id') est-invalide @enderror" required>
                                    <option value="">{{ $b['choisir'] }}</option>
                                    @foreach ($sitesModifiables as $s)
                                        <option value="{{ $s->id }}" @selected((int) old('site_id', $siteFiltre) === $s->id)>{{ $s->code }} · {{ $s->nom }}</option>
                                    @endforeach
                                </select>
                                @error('site_id') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
                            </div>
                            <div class="formulaire-champ !mt-0">
                                <label for="import-fichier">{{ $x['importer'] }}</label>
                                <input id="import-fichier" name="fichier" type="file" class="champ @error('fichier') est-invalide @enderror"
                                       accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">
                                @error('fichier') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
                            </div>
                        </div>
                        <div class="mt-3 flex flex-wrap gap-3">
                            <button type="submit" formmethod="GET" formaction="{{ route('admin.referencement.boutiques.excel.modele') }}" formenctype="application/x-www-form-urlencoded" formnovalidate
                                    class="bo-bouton bo-bouton-secondaire">{{ $x['modele'] }}</button>
                            <button type="submit" class="bo-bouton bo-bouton-principal">{{ $x['importer'] }}</button>
                        </div>
                    @endif
                </form>

                <form method="GET" action="{{ route('admin.referencement.boutiques.excel.export') }}" class="rounded-charte border border-ligne p-4">
                    <p class="texte-carte">{{ $b['excel_export_aide'] }}</p>
                    <div class="mt-3 flex flex-wrap items-end gap-3">
                        <div class="formulaire-champ !mt-0">
                            <label for="export-site">{{ $b['choisir_site'] }}</label>
                            <select id="export-site" name="site_id" class="champ" required>
                                <option value="">{{ $b['choisir'] }}</option>
                                @foreach ($sites as $s)
                                    <option value="{{ $s->id }}" @selected($siteFiltre === $s->id)>{{ ($s->code ? $s->code.' · ' : '').$s->nom }}</option>
                                @endforeach
                            </select>
                        </div>
                        <button type="submit" class="bo-bouton bo-bouton-secondaire">{{ $x['exporter'] }}</button>
                    </div>
                </form>
            </div>

            @if ($imports->isNotEmpty())
                <h3 class="mt-4 !text-base">{{ $x['journal_titre'] }}</h3>
                <ul class="texte-carte mt-1 space-y-1">
                    @foreach ($imports as $import)
                        <li>{{ __('admin.sites.excel.journal_ligne', [
                            'date' => $import->created_at->format('d/m/Y H:i'), 'auteur' => $import->acteur?->name ?? '—',
                            'fichier' => $import->nouvelle_valeur['fichier'] ?? '', 'empreinte' => substr($import->nouvelle_valeur['empreinte'] ?? '', 0, 12),
                            'creees' => $import->nouvelle_valeur['creees'] ?? 0, 'mises_a_jour' => $import->nouvelle_valeur['mises_a_jour'] ?? 0,
                        ]) }}</li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endif
</x-admin.layout>
