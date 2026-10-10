{{-- « Qui finance Raaga ? » : chiffres du projet (paramètres de la plateforme). Variable : $projet. --}}
@php
    $totalFinancement = array_sum(array_column($projet['repartitionFinancement'], 'montant'));
    // Part « commerçants et population » : lignes vertes et jaunes (les grises = financements institutionnels).
    $pctCommercantsPopulation = (int) round(collect($projet['repartitionFinancement'])->whereIn('couleur', ['vert', 'jaune'])->sum('montant') * 100 / max(1, $totalFinancement));
@endphp

    @if ($projet['repartitionFinancement'] && $totalFinancement > 0)
        <section aria-labelledby="financement-titre" class="section section-grise">
            <div class="conteneur">
                <p class="surtitre">{{ __('public.accueil.financement.surtitre') }}</p>
                <h2 id="financement-titre">{{ __('public.accueil.financement.titre') }}</h2>
                <p class="section-intro">{{ __('public.accueil.financement.intro', ['n' => __('public.accueil.financement.nombres')[count($projet['repartitionFinancement'])] ?? count($projet['repartitionFinancement']), 'pct' => $pctCommercantsPopulation]) }}</p>

                <div class="carte-blanche financement">
                    @foreach ($projet['repartitionFinancement'] as $poste)
                        @php($montant = format_md($poste['montant'], 2))
                        <div class="financement-ligne">
                            <span class="financement-libelle">{{ $poste['libelle'] }}</span>
                            <div class="financement-barre" aria-hidden="true">
                                <span class="remplissage-{{ $poste['couleur'] }}" style="width: {{ round($poste['montant'] * 100 / $totalFinancement, 1) }}%"></span>
                            </div>
                            <span class="financement-valeur">{{ $poste['approximatif'] ? __('public.accueil.financement.environ', ['montant' => $montant]) : $montant }}</span>
                        </div>
                    @endforeach
                </div>
                @if ($projet['coutTotalProjet'])
                    <p class="note">{{ __('public.accueil.financement.note', ['total' => format_nombre($projet['coutTotalProjet'] / 1_000_000_000, 3)]) }}</p>
                @endif
            </div>
        </section>
    @endif
