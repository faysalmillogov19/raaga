{{-- Capacité prévue / référentiel : par niveau et par type, écarts signalés ; détail des capacités importées. --}}
@php
    $k = __('admin.sites.capacites');
    $etiquettes = ['conforme' => 'bo-etiquette-succes', 'ecart' => 'bo-etiquette-echec', 'a_completer' => 'bo-etiquette-attention', 'non_prevu' => 'bo-etiquette-attention'];
    $surface = fn (?float $s) => $s === null ? '—' : format_nombre($s, fmod($s, 1.0) == 0.0 ? 0 : 2);
    $signe = fn ($n, $f) => ($n > 0 ? '+' : '').$f($n);
@endphp
<section class="bo-carte" aria-labelledby="capacites-titre">
    <h2 id="capacites-titre" class="!text-[1.15rem]">{{ $k['titre'] }}</h2>
    <p class="texte-carte mt-1">{{ $k['aide'] }}</p>

    @if ($comparaison->isEmpty())
        <p class="mt-3">{{ $k['aucune'] }}</p>
    @else
        <div class="mt-3 overflow-x-auto">
            <table class="bo-tableau">
                <thead><tr>
                    <th scope="col">{{ $k['niveau'] }}</th><th scope="col">{{ $k['type'] }}</th>
                    <th scope="col" class="montant">{{ $k['nombre_prevu'] }}</th><th scope="col" class="montant">{{ $k['nombre_reel'] }}</th>
                    <th scope="col" class="montant">{{ $k['surface_prevue'] }}</th><th scope="col" class="montant">{{ $k['surface_reelle'] }}</th>
                    <th scope="col">{{ $k['ecart'] }}</th>
                </tr></thead>
                <tbody>
                    @foreach ($comparaison as $l)
                        <tr>
                            <td>{{ $l['niveau']?->label() ?? $k['tous_niveaux'] }}</td>
                            <td>{{ $l['type']->code ? $l['type']->code.' · ' : '' }}{{ $l['type']->libelle }}
                                @if ($l['zones'])<br><span class="texte-carte">{{ __('admin.sites.capacites.zones', ['zones' => implode(', ', $l['zones'])]) }}</span>@endif</td>
                            <td class="montant">{{ $l['nombre_prevu'] !== null ? format_nombre($l['nombre_prevu']) : '—' }}</td>
                            <td class="montant">{{ format_nombre($l['nombre_reel']) }}</td>
                            <td class="montant">{{ $surface($l['surface_prevue']) }}</td>
                            <td class="montant">{{ $surface($l['surface_reelle']) }}</td>
                            <td>
                                <span class="bo-etiquette {{ $etiquettes[$l['etat']] }}">{{ $k[$l['etat']] }}</span>
                                @if ($l['etat'] === 'ecart')
                                    @if ($l['ecart_nombre'])<br><span class="text-sm">{{ __('admin.sites.capacites.ecart_nombre', ['ecart' => $signe($l['ecart_nombre'], fn ($n) => format_nombre($n))]) }}</span>@endif
                                    @if ($l['ecart_surface'] !== null && abs($l['ecart_surface']) > 0.01)<br><span class="text-sm">{{ __('admin.sites.capacites.ecart_surface', ['ecart' => $signe($l['ecart_surface'], $surface)]) }}</span>@endif
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($capacites->isNotEmpty())
            <details class="mt-4">
                <summary class="cursor-pointer font-semibold text-vert-fonce">{{ $k['detail'] }} ({{ format_nombre($capacites->count()) }})</summary>
                <div class="mt-2 overflow-x-auto">
                    <table class="bo-tableau">
                        <thead><tr>
                            <th scope="col">{{ $k['niveau'] }}</th><th scope="col">{{ $k['zone'] }}</th><th scope="col">{{ $k['type'] }}</th>
                            <th scope="col" class="montant">{{ $k['nombre_prevu'] }}</th><th scope="col" class="montant">{{ $k['surface_unitaire'] }}</th>
                            <th scope="col" class="montant">{{ $k['surface_retenue'] }}</th><th scope="col">{{ $k['source'] }}</th><th scope="col">{{ $k['observations'] }}</th>
                        </tr></thead>
                        <tbody>
                            @foreach ($capacites as $cap)
                                <tr>
                                    <td>{{ $cap->niveau?->label() ?? '—' }}</td><td>{{ $cap->zone ?? '—' }}</td><td>{{ $cap->type->code ?? $cap->type->libelle }}</td>
                                    <td class="montant">{{ $cap->nombre_prevu !== null ? format_nombre($cap->nombre_prevu) : '—' }}</td>
                                    <td class="montant">{{ $surface($cap->surface_unitaire !== null ? (float) $cap->surface_unitaire : null) }}</td>
                                    <td class="montant">{{ $surface($cap->surfaceRetenue()) }}@if ($cap->estIncomplete())<br><span class="bo-etiquette bo-etiquette-attention">{{ $k['a_completer'] }}</span>@endif</td>
                                    <td>{{ $cap->source ?? '—' }}</td><td>{{ $cap->observations ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </details>
        @endif
    @endif
</section>
