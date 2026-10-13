{{-- Référentiel en Excel : modèle, import (site en brouillon) et export ; journal des derniers imports. --}}
@php
    $x = __('admin.sites.excel');
    $imports = \App\Http\Controllers\Admin\ImportBoutiquesController::journal($site);
@endphp
<div class="mt-4 rounded-charte border border-ligne p-4">
    <h3 class="!text-base">{{ $x['titre'] }}</h3>
    <p class="texte-carte">{{ __('admin.sites.excel.aide', ['taille' => format_nombre(config('raaga.referentiel_boutiques.taille_max_ko') / 1024)]) }}</p>
    <div class="mt-3 flex flex-wrap items-end gap-3">
        @if ($modifiable)
            <a href="{{ route('admin.sites.modele', $site) }}" class="bo-bouton bo-bouton-secondaire">{{ $x['modele'] }}</a>
        @endif
        <a href="{{ route('admin.sites.export', $site) }}" class="bo-bouton bo-bouton-secondaire">{{ $x['exporter'] }}</a>
        @if ($modifiable && $site->code !== null)
            <form method="POST" action="{{ route('admin.sites.import.deposer', $site) }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-3" novalidate>
                @csrf
                <div class="formulaire-champ !mt-0">
                    <label for="import-fichier">{{ $x['importer'] }}</label>
                    <input id="import-fichier" name="fichier" type="file" required class="champ @error('fichier') est-invalide @enderror"
                           accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">
                </div>
                <button type="submit" class="bo-bouton bo-bouton-principal">{{ $x['importer'] }}</button>
            </form>
        @endif
    </div>
    @error('fichier') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror

    @if ($imports->isNotEmpty())
        <h4 class="mt-4 font-semibold">{{ $x['journal_titre'] }}</h4>
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
</div>
