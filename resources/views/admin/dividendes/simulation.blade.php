@php
    $d = $distribution;
    $r = $resultat;
    $s = fn (string $cle, array $p = []) => __('admin.dividendes.simulation.'.$cle, $p);
    $fraisDeduits = $r->fraisParActionnaire ? $r->totalFrais : 0;
    $totalControle = $r->totalNetAPayer + $r->totalRetenue + $fraisDeduits + $r->totalReporte + $r->totalConsigne + $r->reliquat;
    $titre = $s('titre', ['annee' => $d->exercice]);
@endphp

<x-admin.layout :titre="$titre" :fil="[['libelle' => __('admin.dividendes.titre'), 'url' => route('admin.dividendes')], ['libelle' => __('admin.dividendes.exercice', ['annee' => $d->exercice]), 'url' => route('admin.dividendes.show', $d)], ['libelle' => __('admin.dividendes.actions.simuler')]]">
    <nav class="bo-onglets" aria-label="{{ $s('source') }}">
        <a href="{{ route('admin.dividendes.simulation', $d) }}" @class(['bo-onglet', 'bo-onglet-actif' => $source === 'registre'])>{{ $s('source_registre', ['date' => $d->date_arrete->format('d/m/Y')]) }}</a>
        <a href="{{ route('admin.dividendes.simulation', [$d, 'source' => 'demo']) }}" @class(['bo-onglet', 'bo-onglet-actif' => $source === 'demo'])>{{ $s('source_demo') }}</a>
    </nav>

    @if ($source === 'registre' && $r->nbBeneficiaires === 0)
        <x-admin.message type="erreur">{{ $s('registre_vide') }}</x-admin.message>
    @endif

    <section class="bo-carte !mt-0" aria-labelledby="synthese-titre">
        <h2 id="synthese-titre" class="sr-only">Synthèse</h2>
        <dl class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ([
                [$s('beneficiaires'), format_nombre($r->nbBeneficiaires)],
                [$s('actions'), format_nombre($r->nbActions)],
                [$s('dpa'), format_fcfa($r->dividendeParAction)],
                [ucfirst(__('admin.dividendes.champs.montant_global')), format_fcfa($r->montantGlobal)],
                [$s('brut'), format_fcfa($r->totalBrut)],
                [$s('retenue'), format_fcfa($r->totalRetenue)],
                [$s('net'), format_fcfa($r->totalNetAPayer).' · '.$s('versements', ['n' => format_nombre($r->nbAPayer)])],
                [$s('frais').' ('.($r->fraisParActionnaire ? $s('frais_actionnaire') : $s('frais_raaga')).')', format_fcfa($r->totalFrais)],
                [$s('reportes'), format_fcfa($r->totalReporte).' · '.format_nombre($r->nbReportes)],
                [$s('consignes'), format_fcfa($r->totalConsigne).' · '.format_nombre($r->nbConsignes)],
                [$s('reliquat'), format_fcfa($r->reliquat)],
            ] as [$libelle, $valeur])
                <div class="flex flex-col-reverse rounded-charte border border-ligne p-4">
                    <dt class="texte-carte">{{ $libelle }}</dt>
                    <dd class="text-[1.15rem] font-extrabold text-encre">{{ $valeur }}</dd>
                </div>
            @endforeach
        </dl>
    </section>

    <section class="bo-carte" aria-labelledby="canal-titre">
        <h2 id="canal-titre" class="!text-[1.15rem]">{{ $s('par_canal') }}</h2>
        <div class="mt-3 overflow-x-auto">
            <table class="bo-tableau">
                <tbody>
                    @forelse ($r->parCanal as $canal => $ligne)
                        <tr>
                            <td>{{ \App\Models\MoyenPaiement::libelleDe($canal) }}</td>
                            <td class="montant">{{ $s('versements', ['n' => format_nombre($ligne['nombre'])]) }}</td>
                            <td class="montant">{{ format_fcfa($ligne['montant']) }}</td>
                        </tr>
                    @empty
                        <tr><td>—</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="bo-carte" aria-labelledby="controle-titre">
        <h2 id="controle-titre" class="!text-[1.15rem]">{{ $s('controle') }}</h2>
        <p class="mt-1">{{ $s('egalite') }}</p>
        <x-admin.message type="ok" class="mt-3 !mb-0">{{ $s('egalite_ok', ['total' => format_fcfa($totalControle), 'global' => format_fcfa($r->montantGlobal)]) }}</x-admin.message>
    </section>
</x-admin.layout>
