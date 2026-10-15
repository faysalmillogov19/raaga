<x-public.layout :titre="__('public.transparence.titre')">
    <x-public.page-entete :titre="__('public.transparence.titre')" :intro="__('public.transparence.intro')"
                          :fil="[['libelle' => __('public.transparence.titre')]]" />

    <div class="conteneur space-y-12 py-8 tab:py-12">

        {{-- La collecte en chiffres --}}
        <section aria-labelledby="collecte-titre">
            <h2 id="collecte-titre">{{ __('public.transparence.collecte') }}</h2>
            <p class="mt-1 texte-carte">{{ __('public.transparence.maj') }}</p>

            @if ($etat)
                <div class="mt-5 grid grid-cols-[minmax(0,1fr)] gap-4 lg:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)]">
                    <x-public.jauge-collecte :etat="$etat" :lien="false" />
                    <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-1">
                        <div class="carte flex flex-col-reverse p-5">
                            <dt class="texte-carte">{{ __('public.transparence.moyen') }}</dt>
                            <dd class="text-2xl font-extrabold text-encre">{{ format_fcfa($etat->montantMoyen()) }}</dd>
                        </div>
                        @if ($statistiques)
                            <div class="carte flex flex-col-reverse p-5">
                                <dt class="texte-carte">{{ __('public.transparence.median') }}<span class="block texte-carte">{{ __('public.transparence.median_aide') }}</span></dt>
                                <dd class="text-2xl font-extrabold text-encre">{{ format_fcfa($statistiques->montantMedian) }}</dd>
                            </div>
                        @endif
                    </dl>
                </div>
            @else
                <x-public.alerte variante="info" class="mt-5">{{ __('public.communs.aucune_tranche') }}</x-public.alerte>
            @endif
        </section>

        @if ($statistiques)
            <div class="grid grid-cols-[minmax(0,1fr)] gap-10 lg:grid-cols-2">
                {{-- Par moyen de paiement --}}
                <section aria-labelledby="canal-titre">
                    <h2 id="canal-titre">{{ __('public.transparence.par_canal') }}</h2>
                    @php($totalCanaux = max(1, array_sum($statistiques->montantParCanal)))
                    <ul class="mt-5 space-y-4">
                        {{-- Moyens actifs, et tout moyen retiré qui a encore des montants encaissés. --}}
                        @foreach (\App\Models\MoyenPaiement::query()->orderBy('ordre')->get()->filter(fn ($m) => $m->actif || ($statistiques->montantParCanal[$m->code] ?? 0) > 0) as $canal)
                            @php($montant = $statistiques->montantParCanal[$canal->code] ?? 0)
                            <li>
                                <div class="flex flex-wrap items-baseline justify-between gap-x-4">
                                    <span class="font-semibold text-encre">{{ $canal->label() }}</span>
                                    <span><strong class="text-encre">{{ format_fcfa($montant) }}</strong> · {{ pourcentage_lisible($montant, $totalCanaux) }}</span>
                                </div>
                                <div class="mt-1.5 h-3.5 overflow-hidden rounded-full bg-fond ring-1 ring-inset ring-ligne" aria-hidden="true">
                                    <div class="h-full rounded-full bg-vert" style="width: {{ round($montant * 100 / $totalCanaux, 1) }}%"></div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </section>

                {{-- Par tranche de montant --}}
                <section aria-labelledby="montant-titre">
                    <h2 id="montant-titre">{{ __('public.transparence.par_montant') }}</h2>
                    @php($totalSouscripteurs = max(1, array_sum($statistiques->souscripteursParTrancheMontant)))
                    <ul class="mt-5 space-y-4">
                        @foreach ($statistiques->souscripteursParTrancheMontant as $libelle => $nombre)
                            <li>
                                <div class="flex flex-wrap items-baseline justify-between gap-x-4">
                                    <span class="font-semibold text-encre">{{ $libelle }} <span class="font-normal text-gris">{{ __('public.transparence.montant_fcfa') }}</span></span>
                                    <span><strong class="text-encre">{{ __('public.transparence.souscripteurs', ['n' => format_nombre($nombre)]) }}</strong> · {{ pourcentage_lisible($nombre, $totalSouscripteurs) }}</span>
                                </div>
                                <div class="mt-1.5 h-3.5 overflow-hidden rounded-full bg-fond ring-1 ring-inset ring-ligne" aria-hidden="true">
                                    <div class="h-full rounded-full bg-jaune" style="width: {{ round($nombre * 100 / $totalSouscripteurs, 1) }}%"></div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </section>
            </div>
        @endif

        <div class="grid grid-cols-[minmax(0,1fr)] gap-10 lg:grid-cols-2">
            {{-- Chantier --}}
            <section aria-labelledby="chantier-titre">
                <h2 id="chantier-titre">{{ __('public.transparence.chantier') }}</h2>
                @if ($chantier)
                    <x-public.frise class="mt-5" :etapes="array_map(fn ($e) => ['titre' => $e['titre'], 'detail' => $e['periode'], 'statut' => $e['statut']], $chantier)" />
                @else
                    <p class="mt-4 text-gris">{{ __('public.transparence.chantier_vide') }}</p>
                @endif
            </section>

            {{-- Rapports --}}
            <section aria-labelledby="rapports-titre">
                <h2 id="rapports-titre">{{ __('public.transparence.rapports') }}</h2>
                @if ($rapports)
                    <ul class="mt-4 divide-y divide-ligne rounded-carte border border-ligne bg-white">
                        @foreach ($rapports as $rapport)
                            <li class="flex items-center gap-3 p-4">
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-charte bg-rouge-pale text-xs font-bold text-rouge" aria-hidden="true">{{ __('public.communs.pdf') }}</span>
                                <div class="min-w-0">
                                    @if ($rapport['url'])
                                        <a href="{{ $rapport['url'] }}" class="lien" download>{{ $rapport['titre'] }}</a>
                                    @else
                                        <p class="font-semibold text-encre">{{ $rapport['titre'] }}</p>
                                    @endif
                                    <p class="texte-carte">{{ \Carbon\CarbonImmutable::parse($rapport['date'])->translatedFormat('j F Y') }}</p>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="mt-4 text-gris">{{ __('public.transparence.rapports_vide') }}</p>
                @endif
            </section>
        </div>

        {{-- Après l'ouverture du marché --}}
        <section aria-labelledby="apres-titre" class="rounded-carte bg-fond p-5 tab:p-8">
            <h2 id="apres-titre">{{ __('public.transparence.apres.titre') }}</h2>
            <p class="mt-1 text-gris">{{ __('public.transparence.apres.intro') }}</p>
            <dl class="mt-5 grid grid-cols-2 gap-4 lg:grid-cols-4">
                @foreach (['occupation', 'recettes', 'resultat', 'dividende'] as $indicateur)
                    <div class="carte flex flex-col-reverse p-4 text-center">
                        <dt class="mt-1 texte-carte">{{ __('public.transparence.apres.'.$indicateur) }}</dt>
                        <dd class="text-2xl font-extrabold text-gris">—</dd>
                    </div>
                @endforeach
            </dl>
        </section>
    </div>
</x-public.layout>
