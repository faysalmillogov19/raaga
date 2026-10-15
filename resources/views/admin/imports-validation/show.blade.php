{{--
    Détail d'un import : informations, décision du VALIDATEUR sur l'import entier (valider, ou rejeter avec motif),
    sites concernés (situation dans l'import, lien vers chaque site), résumé par site, niveau et type, écart avec les
    capacités prévues, avertissements de l'aperçu, fichier d'origine.
--}}
@php
    use App\Enums\Droit;
    use App\Support\ImportValidationPresentation as P;

    $x = __('admin.imports_validation');
    $statut = $import->statutValidation();
    $resume = P::resume($import);
    $ecarts = P::ecarts($import);
@endphp

<x-admin.layout :titre="__('admin.imports_validation.titre', ['fichier' => $import->fichier])"
                :fil="[['libelle' => __('admin.menu.imports'), 'url' => route('admin.referencement.imports.entite', $import->entite)], ['libelle' => $import->fichier]]">
    <section class="bo-carte !mt-0" aria-labelledby="import-infos">
        <h2 id="import-infos" class="sr-only">{{ $x['infos']['fichier'] }}</h2>
        <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
            <div><dt class="texte-carte">{{ $x['infos']['type'] }}</dt><dd class="font-semibold">{{ $x['entites'][$import->entite] }}</dd></div>
            <div><dt class="texte-carte">{{ $x['infos']['auteur'] }}</dt><dd class="font-semibold">{{ $import->auteur?->name }}, {{ $import->created_at->format('d/m/Y H:i') }}</dd></div>
            <div><dt class="texte-carte">{{ $x['infos']['contenu'] }}</dt><dd class="font-semibold">{{ P::contenu($import) }}</dd></div>
            <div><dt class="texte-carte">{{ $x['infos']['statut'] }}</dt><dd data-statut-validation="{{ $statut }}">{{ P::statut($import) }}</dd></div>
        </dl>
        @if ($import->chemin)
            <p class="mt-3"><a href="{{ route('admin.imports-validation.fichier', $import) }}" class="font-semibold text-vert-fonce underline underline-offset-2">{{ $x['telecharger'] }}</a></p>
        @endif

        @if ($statut === 'SOUMIS')
            @can(Droit::SitesConfirmer->value)
                @if ($import->auteur_id === auth()->id())
                    <x-admin.message type="avertissement" class="!mb-0 mt-4" data-auto-validation>{{ $x['auto'] }}</x-admin.message>
                @else
                    <div class="mt-4 flex flex-wrap gap-3">
                        <x-admin.confirmation id="valider-import" :titre="$x['valider']" :message="$x['valider_message']"
                                              :action="route('admin.imports-validation.confirmer', $import)" :bouton="$x['valider']" variante="principal">
                            <x-slot:declencheur>{{ $x['valider'] }}</x-slot:declencheur>
                        </x-admin.confirmation>
                        <x-admin.confirmation id="rejeter-import" :titre="$x['rejeter']" :message="$x['rejeter_message']"
                                              :action="route('admin.imports-validation.rejeter', $import)" :bouton="$x['rejeter']" motif danger>
                            <x-slot:declencheur>{{ $x['rejeter'] }}…</x-slot:declencheur>
                        </x-admin.confirmation>
                    </div>
                    @error('motif') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
                @endif
            @endcan
        @endif
    </section>

    <section class="bo-carte" aria-labelledby="import-sites">
        <h2 id="import-sites" class="!text-[1.15rem]">{{ $x['sites_titre'] }}</h2>
        <div class="mt-3 overflow-x-auto">
            <table class="bo-tableau">
                <thead><tr>@foreach ($x['colonnes_sites'] as $libelle)<th scope="col">{{ $libelle }}</th>@endforeach</tr></thead>
                <tbody>
                    @foreach ($import->sites as $ligne)
                        <tr data-site="{{ $ligne->site->code }}">
                            <td><a href="{{ route('admin.sites.show', $ligne->site) }}" class="font-bold text-vert-fonce underline underline-offset-2">{{ ($ligne->site->code ? $ligne->site->code.' · ' : '').$ligne->site->nom }}</a></td>
                            <td><x-admin.badge-statut :statut="$ligne->site->statut" /></td>
                            <td>{{ P::situation($ligne) }}</td>
                            <td>{{ $ligne->raison_brouillon }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="bo-carte" aria-labelledby="import-resume">
        <h2 id="import-resume" class="!text-[1.15rem]">{{ $x['resume_titre'] }}</h2>
        <div class="mt-3 overflow-x-auto">
            <table class="bo-tableau">
                <thead><tr>
                    @foreach (['site', 'niveau', 'type', 'lignes'] as $c)<th scope="col">{{ $x['colonnes_resume'][$c] }}</th>@endforeach
                    @if ($import->entite === 'boutiques')
                        <th scope="col" class="montant">{{ $x['colonnes_resume']['creees'] }}</th><th scope="col" class="montant">{{ $x['colonnes_resume']['mises_a_jour'] }}</th>
                    @else
                        <th scope="col" class="montant">{{ $x['colonnes_resume']['prevu'] }}</th>
                    @endif
                </tr></thead>
                <tbody>
                    @foreach ($resume as $r)
                        <tr>
                            <td>{{ $r['site'] }}</td><td>{{ $r['niveau'] }}</td><td>{{ $r['type'] }}</td><td class="montant">{{ $r['lignes'] }}</td>
                            @if ($import->entite === 'boutiques')
                                <td class="montant">{{ $r['creees'] }}</td><td class="montant">{{ $r['mises_a_jour'] }}</td>
                            @else
                                <td class="montant">{{ $r['nombre_prevu'] }}</td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="bo-carte" aria-labelledby="import-ecarts">
        <h2 id="import-ecarts" class="!text-[1.15rem]">{{ $x['ecarts_titre'] }}</h2>
        @if ($ecarts->isEmpty())
            <p class="texte-carte mt-2">{{ $x['aucun_ecart'] }}</p>
        @else
            <div class="mt-3 overflow-x-auto">
                <table class="bo-tableau">
                    <thead><tr>@foreach ($x['colonnes_ecarts'] as $libelle)<th scope="col">{{ $libelle }}</th>@endforeach</tr></thead>
                    <tbody>
                        @foreach ($ecarts as $e)
                            <tr><td>{{ $e['site'] }}</td><td>{{ $e['niveau'] }}</td><td>{{ $e['type'] }}</td><td class="montant">{{ $e['prevu'] }}</td>
                                <td class="montant">{{ $e['reel'] }}</td><td class="montant font-semibold">{{ $e['ecart'] > 0 ? '+' : '' }}{{ $e['ecart'] }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="bo-carte" aria-labelledby="import-avertissements">
        <h2 id="import-avertissements" class="!text-[1.15rem]">{{ $x['avertissements_titre'] }}</h2>
        @forelse ($import->avertissements as $a)
            <p class="mt-1">{{ $a }}</p>
        @empty
            <p class="texte-carte mt-2">{{ $x['aucun_avertissement'] }}</p>
        @endforelse
    </section>
</x-admin.layout>
