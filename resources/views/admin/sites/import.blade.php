@php
    $x = __('admin.sites.excel');
    $erreurs = $analyse->erreurs();
    $valides = count($analyse->lignes());
    // Import pour un site choisi (onglet Boutiques ou Imports), ou import combiné (plusieurs sites).
    $fil = $site
        ? [['libelle' => __('admin.referencement.boutiques.titre'), 'url' => route('admin.referencement.boutiques', ['site' => $site->id])], ['libelle' => $site->nom]]
        : [['libelle' => __('admin.sites.titre'), 'url' => route('admin.sites')]];
    $fil[] = ['libelle' => $x['apercu_titre']];
    $routeConfirmer = $site ? route('admin.referencement.boutiques.excel.import.confirmer', $site) : route('admin.sites.boutiques-excel.confirmer');
    $routeAnnuler = $site ? route('admin.referencement.boutiques.excel.import.annuler', $site) : route('admin.sites.boutiques-excel.annuler');
@endphp

<x-admin.layout :titre="$x['apercu_titre']" :fil="$fil">
    {{-- Bandeau fixe : l'aperçu n'enregistre rien ; le bouton « Confirmer l'import » est répété en haut et en bas. --}}
    <div class="adm-bandeau-apercu" role="status">
        <p class="font-semibold">{{ $x['bandeau'] }}</p>
        @if ($erreurs === [])
            <x-admin.confirmation id="confirmer-import-haut" :titre="$x['confirmer']" :message="$x['confirmer_message']"
                                  :action="$routeConfirmer" :bouton="$x['confirmer']" variante="principal">
                <x-slot:declencheur>{{ $x['confirmer'] }}</x-slot:declencheur>
                <x-admin.statut-apres-import id="statut-1" />
            </x-admin.confirmation>
        @else
            <x-admin.bouton variante="principal" type="button" disabled aria-describedby="erreurs-aide">{{ $x['confirmer'] }}</x-admin.bouton>
        @endif
    </div>

    <section class="bo-carte" aria-labelledby="apercu-titre">
        <h2 id="apercu-titre" class="!text-[1.15rem]">{{ trans_choice('admin.sites.excel.lignes_valides', $valides, ['n' => format_nombre($valides)]) }}</h2>
        <p class="texte-carte mt-1">{{ __('admin.sites.excel.apercu_intro', ['nom' => $depot['nom'], 'empreinte' => substr($depot['empreinte'], 0, 12).'…']) }}</p>
        @if ($valides > 0)
            <p class="mt-2">{{ __('admin.sites.excel.creations', ['n' => format_nombre($analyse->creations())]) }} · {{ __('admin.sites.excel.mises_a_jour', ['n' => format_nombre($analyse->misesAJour())]) }}</p>
            <div class="mt-4 grid gap-[18px] md:grid-cols-2">
                @foreach (['par_niveau' => $analyse->parNiveau(), 'par_type' => $analyse->parType()] as $titre => $totaux)
                    <div>
                        <h3 class="!text-base">{{ $x[$titre] }}</h3>
                        <table class="bo-tableau mt-2">
                            <tbody>
                                @foreach ($totaux as $libelle => $n)
                                    <tr><th scope="row" class="font-semibold">{{ $libelle }}</th><td class="montant">{{ format_nombre($n) }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endforeach
            </div>
        @endif

        @if ($analyse->avertissements() !== [])
            {{-- Avertissements : n'empêchent pas la confirmation. --}}
            <x-admin.message type="avertissement" class="mt-4 !mb-0">
                <p class="font-semibold">{{ $x['avertissements_titre'] }}</p>
                <ul class="mt-1 list-disc pl-5">
                    @foreach ($analyse->avertissements() as $avertissement)<li>{{ $avertissement }}</li>@endforeach
                </ul>
            </x-admin.message>
        @endif

        <div class="mt-5 flex flex-wrap gap-3">
            @if ($erreurs === [])
                {{-- Action principale de la page : rien n'est enregistré avant ce clic (puis la confirmation). --}}
                <x-admin.confirmation id="confirmer-import" :titre="$x['confirmer']" :message="$x['confirmer_message']"
                                      :action="$routeConfirmer" :bouton="$x['confirmer']" variante="principal">
                    <x-slot:declencheur>{{ $x['confirmer'] }}</x-slot:declencheur>
                    <x-admin.statut-apres-import id="statut-2" />
                </x-admin.confirmation>
            @else
                <x-admin.bouton variante="principal" type="button"  disabled aria-describedby="erreurs-aide">{{ $x['confirmer'] }}</x-admin.bouton>
            @endif
            <form method="POST" action="{{ $routeAnnuler }}">
                @csrf
                <x-admin.bouton variante="contour" type="submit">{{ $x['annuler'] }}</x-admin.bouton>
            </form>
        </div>
    </section>

    @if ($erreurs !== [])
        <section class="bo-carte" aria-labelledby="erreurs-titre">
            <h2 id="erreurs-titre" class="!text-[1.15rem]">{{ trans_choice('admin.sites.excel.erreurs_titre', count($erreurs), ['n' => format_nombre(count($erreurs))]) }}</h2>
            <p id="erreurs-aide" class="texte-carte mt-1">{{ $x['erreurs_aide'] }}</p>
            <div class="mt-3 overflow-x-auto">
                <table class="bo-tableau">
                    <thead><tr><th scope="col">{{ $x['colonne_ligne'] }}</th><th scope="col">{{ $x['colonne_colonne'] }}</th><th scope="col">{{ $x['colonne_motif'] }}</th></tr></thead>
                    <tbody>
                        @foreach ($erreurs as $erreur)
                            <tr>
                                <td>{{ $erreur['ligne'] ?? '—' }}</td>
                                <td>{{ $erreur['colonne'] ?? $x['general'] }}</td>
                                <td>{{ $erreur['motif'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</x-admin.layout>
