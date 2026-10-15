@php
    $x = __('admin.sites.import');
    $erreurs = $analyse->erreurs();
    $listes = ['a_creer' => $analyse->sitesACreer(), 'a_mettre_a_jour' => $analyse->sitesAMettreAJour()];
@endphp

<x-admin.layout :titre="$x['apercu_titre']" :fil="[['libelle' => __('admin.sites.titre'), 'url' => route('admin.sites')], ['libelle' => $x['apercu_titre']]]">
    <section class="bo-carte !mt-0" aria-labelledby="apercu-titre">
        <h2 id="apercu-titre" class="!text-[1.15rem]">{{ $x['apercu_titre'] }}</h2>
        <p class="texte-carte mt-1">{{ __('admin.sites.import.apercu_intro', ['nom' => $depot['nom'], 'empreinte' => substr($depot['empreinte'], 0, 12).'…']) }}</p>

        <div class="mt-4 grid gap-[18px] lg:grid-cols-3">
            @foreach ($listes as $cle => $sites)
                <div>
                    <h3 class="!text-base">{{ $x[$cle] }} ({{ format_nombre(count($sites)) }})</h3>
                    @if ($sites === [])
                        <p class="texte-carte">{{ $x['aucun'] }}</p>
                    @else
                        <ul class="mt-1 space-y-1">
                            @foreach ($sites as $code => $s)
                                <li><span class="font-mono font-semibold">{{ $code }}</span> · {{ $s['donnees']['nom'] }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endforeach
            <div>
                <h3 class="!text-base">{{ $x['capacites'] }} ({{ format_nombre($analyse->nombreCapacites()) }})</h3>
                <ul class="mt-1 space-y-1">
                    @foreach ($analyse->capacites() as $code => $capacites)
                        <li>{{ __('admin.sites.import.capacites_site', ['code' => $code, 'n' => format_nombre(count($capacites))]) }}</li>
                    @endforeach
                </ul>
            </div>
        </div>

        <div class="mt-5 flex flex-wrap gap-3">
            @if ($erreurs === [])
                <x-admin.confirmation id="confirmer-import-sites" :titre="$x['confirmer']" :message="$x['confirmer_message']"
                                      :action="route('admin.sites.excel.confirmer')" :bouton="$x['confirmer']">
                    <x-slot:declencheur>{{ $x['confirmer'] }}</x-slot:declencheur>
                    <x-admin.statut-apres-import id="statut-sites" />
                </x-admin.confirmation>
            @else
                <x-admin.bouton variante="principal" type="button"  disabled aria-describedby="erreurs-aide">{{ $x['confirmer'] }}</x-admin.bouton>
            @endif
            <form method="POST" action="{{ route('admin.sites.excel.annuler') }}">
                @csrf
                <x-admin.bouton variante="contour" type="submit">{{ $x['annuler'] }}</x-admin.bouton>
            </form>
        </div>
    </section>

    @if ($erreurs !== [])
        <section class="bo-carte" aria-labelledby="erreurs-titre">
            <h2 id="erreurs-titre" class="!text-[1.15rem]">{{ trans_choice('admin.sites.import.erreurs_titre', count($erreurs), ['n' => format_nombre(count($erreurs))]) }}</h2>
            <p id="erreurs-aide" class="texte-carte mt-1">{{ $x['erreurs_aide'] }}</p>
            <div class="mt-3 overflow-x-auto">
                <table class="bo-tableau">
                    <thead><tr><th scope="col">{{ $x['colonne_onglet'] }}</th><th scope="col">{{ $x['colonne_ligne'] }}</th><th scope="col">{{ $x['colonne_colonne'] }}</th><th scope="col">{{ $x['colonne_motif'] }}</th></tr></thead>
                    <tbody>
                        @foreach ($erreurs as $erreur)
                            <tr>
                                <td>{{ $erreur['onglet'] ?? $x['general'] }}</td>
                                <td>{{ $erreur['ligne'] ?? '—' }}</td>
                                <td>{{ $erreur['colonne'] ?? '—' }}</td>
                                <td>{{ $erreur['motif'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</x-admin.layout>
