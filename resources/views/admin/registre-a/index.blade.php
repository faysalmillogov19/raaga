@php
    $r = __('institutionnel.bo.registre_a');
    $col = $r['colonnes'];
@endphp

<x-admin.layout :titre="$r['titre']" :fil="[['libelle' => $r['titre']]]">
    <section class="bo-carte !mt-0" aria-labelledby="repartition-titre">
        <h2 id="repartition-titre" class="!text-[1.15rem]">{{ $r['repartition'] }}</h2>
        <dl class="mt-3 grid gap-4 sm:grid-cols-3">
            @foreach ([[$r['actions_a'], $repartition['a'], $repartition['pct_a']], [$r['actions_b'], $repartition['b'], $repartition['pct_b']], [$r['total'], $repartition['total'], $repartition['total'] ? 100 : 0]] as [$libelle, $n, $pct])
                <div class="flex flex-col-reverse rounded-charte border border-ligne p-4">
                    <dt class="texte-carte">{{ $libelle }}</dt>
                    <dd class="text-[1.15rem] font-extrabold text-encre">{{ format_nombre($n) }} · {{ format_nombre($pct, 2) }}&nbsp;%</dd>
                </div>
            @endforeach
        </dl>
        <p @class(['bo-message mt-4 !mb-0', 'bo-message-erreur' => ! $chaineIntegre])>{{ $chaineIntegre ? $r['chaine_ok'] : $r['chaine_ko'] }}</p>
    </section>

    <section class="bo-carte" aria-labelledby="lignes-titre">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 id="lignes-titre" class="!text-[1.15rem]">{{ $r['titre'] }}</h2>
                <p class="texte-carte mt-1 max-w-3xl">{{ $r['intro'] }}</p>
            </div>
            @can(\App\Enums\Droit::RegistreAExporter->value)
                <x-admin.bouton variante="contour" href="{{ route('admin.registre-a.export') }}">{{ $r['exporter'] }}</x-admin.bouton>
            @endcan
        </div>
        <div class="mt-3 overflow-x-auto">
            <table class="bo-tableau">
                <thead><tr>@foreach ($col as $cle => $libelle)<th scope="col" @class(['montant' => $cle === 'actions'])>{{ $libelle }}</th>@endforeach</tr></thead>
                <tbody>
                    @forelse ($lignes as $l)
                        <tr>
                            <td class="font-mono">{{ $l->numero_certificat }}</td>
                            <td>{{ $l->institution->nomCourt() }}</td>
                            <td class="montant">{{ format_nombre($l->nb_actions) }}</td>
                            <td class="whitespace-nowrap">{{ format_nombre($l->numero_debut) }} – {{ format_nombre($l->numero_fin) }}</td>
                            <td>{{ $l->date_inscription->format('d/m/Y') }}</td>
                            <td>{{ $l->convention->reference }}</td>
                            <td><a href="{{ route('admin.registre-a.attestation', $l) }}" class="lien-action" target="_blank">{{ $r['telecharger'] }}</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="7">{{ $r['aucune'] }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $lignes->links('admin._pagination') }}</div>
    </section>
</x-admin.layout>
