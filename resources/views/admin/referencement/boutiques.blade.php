@php
    use App\Enums\Droit;
    use App\Enums\EtatPhysiqueBoutique;
    use App\Models\Niveau;
    use App\Enums\StatutBoutique;

    $b = __('admin.referencement.boutiques');
    $x = __('admin.sites.excel');
    $gerer = auth()->user()->can(Droit::SitesGerer->value);
    $siteFiltre = (int) ($filtres['site'] ?? 0);
    $select = fn (string $nom, string $libelle, iterable $options) => compact('nom', 'libelle', 'options');
    $listes = [
        $select('site', $b['site'], $sites->mapWithKeys(fn ($s) => [$s->id => ($s->code ? $s->code.' · ' : '').$s->nom])),
        $select('niveau', $b['niveau'], Niveau::query()->orderBy('rang')->get()->mapWithKeys(fn ($n) => [$n->code => $n->code.' · '.$n->libelle])),
        $select('zone', $b['zone'], $zones->mapWithKeys(fn ($z) => [$z->code => $z->code.' · '.$z->libelle])),
        $select('type', $b['type'], $types->mapWithKeys(fn ($t) => [$t->id => ($t->code ? $t->code.' · ' : '').$t->libelle])),
        $select('etat', $b['etat'], collect(EtatPhysiqueBoutique::cases())->mapWithKeys(fn ($e) => [$e->value => $e->label()])),
        $select('statut', $b['statut'], collect(StatutBoutique::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])),
    ];
@endphp

<x-admin.layout :titre="$b['titre']" :fil="[['libelle' => $b['titre']]]">
    <div class="bo-carte !mt-0">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <p class="texte-carte max-w-3xl">{{ $b['intro'] }}</p>
            @if ($gerer)
                <a href="{{ route('admin.referencement.boutiques.ajouter', $sitesModifiables->contains('id', $siteFiltre) ? ['site' => $siteFiltre] : []) }}" class="bo-bouton bo-bouton-principal">{{ $b['ajouter'] }}</a>
            @endif
        </div>

        {{-- Recherche et filtres --}}
        <form method="GET" action="{{ route('admin.referencement.boutiques') }}" class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4" role="search">
            <x-admin.champ nom="q" :libelle="$b['recherche']" :valeur="$filtres['q'] ?? null" maxlength="30" />
            @foreach ($listes as $l)
                <div class="formulaire-champ !mt-0">
                    <label for="filtre-{{ $l['nom'] }}">{{ $l['libelle'] }}</label>
                    <select id="filtre-{{ $l['nom'] }}" name="{{ $l['nom'] }}" class="champ">
                        <option value="">{{ $b['tous'] }}</option>
                        @foreach ($l['options'] as $valeur => $libelle)
                            <option value="{{ $valeur }}" @selected((string) ($filtres[$l['nom']] ?? '') === (string) $valeur)>{{ $libelle }}</option>
                        @endforeach
                    </select>
                </div>
            @endforeach
            <div class="flex flex-wrap items-end gap-3 xl:col-span-1">
                <button type="submit" class="bo-bouton bo-bouton-principal">{{ $b['filtrer'] }}</button>
                <a href="{{ route('admin.referencement.boutiques') }}" class="bo-bouton bo-bouton-secondaire">{{ $b['effacer'] }}</a>
            </div>
        </form>

        {{-- Compteurs --}}
        <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
            <p class="font-semibold text-encre">{{ trans_choice('admin.referencement.boutiques.total', $compteurs['total'], ['n' => format_nombre($compteurs['total'])]) }}
                @foreach (StatutBoutique::cases() as $s)
                    @if ($compteurs['statuts'][$s->value] ?? 0)<span class="texte-carte font-normal"> · {{ $s->label() }} : {{ format_nombre($compteurs['statuts'][$s->value]) }}</span>@endif
                @endforeach
            </p>
            <a href="{{ route('admin.referencement.boutiques.export', request()->query()) }}" class="bo-bouton bo-bouton-secondaire">{{ $b['exporter'] }}</a>
        </div>
        <p class="texte-carte">
            @foreach (EtatPhysiqueBoutique::cases() as $e)
                @if ($compteurs['etats'][$e->value] ?? 0){{ $e->label() }} : {{ format_nombre($compteurs['etats'][$e->value]) }} · @endif
            @endforeach
            @if ($compteurs['etats'][''] ?? 0){{ $b['non_renseigne'] }} : {{ format_nombre($compteurs['etats']['']) }}@endif
        </p>

        @if ($boutiques->isEmpty())
            <x-etat-vide icone="info" :titre="$b['vide_titre']">{{ $b['vide'] }}</x-etat-vide>
        @else
            <div class="mt-3 overflow-x-auto">
                <table class="bo-tableau">
                    <thead><tr>@foreach ($b['colonnes'] as $cle => $libelle)<th scope="col" @class(['montant' => $cle === 'surface'])>{{ $libelle }}</th>@endforeach</tr></thead>
                    <tbody>
                        @foreach ($boutiques as $boutique)
                            <tr>
                                <td class="whitespace-nowrap font-mono font-semibold"><a href="{{ route('admin.referencement.boutiques.show', $boutique) }}" class="text-vert-fonce underline underline-offset-2">{{ $boutique->reference() }}</a></td>
                                <td>{{ $boutique->site->code ?? $boutique->site->nom }}</td>
                                <td>{{ $boutique->libelleNiveau() ?? '—' }}</td>
                                <td>{{ $boutique->bloc ?: '—' }}</td>
                                <td>{{ $boutique->type->code ?? $boutique->type->libelle }}</td>
                                <td class="montant">{{ $boutique->surface !== null ? format_nombre((float) $boutique->surface, 2) : '—' }}</td>
                                <td>{{ $boutique->etat_physique?->label() ?? $b['non_renseigne'] }}</td>
                                <td>{{ $boutique->statut->label() }}</td>
                                <td>
                                    @php
                                        $motif = ! $boutique->site->estModifiable() ? $b['site_fige'] : ($boutique->statut->verrouillee() ? $b['verrouillee'] : null);
                                    @endphp
                                    <div class="flex gap-2">
                                        <x-admin.bouton-icone :href="route('admin.referencement.boutiques.show', $boutique)" icone="voir" :intitule="$b['voir'].' '.$boutique->reference()" :infobulle="$b['voir']" />
                                        @if ($gerer)
                                            @if ($motif)
                                                <x-admin.bouton-icone icone="modifier" :intitule="$b['modifier_court'].' '.$boutique->reference()" :infobulle="$motif" disabled />
                                            @else
                                                <x-admin.bouton-icone :href="route('admin.referencement.boutiques.edit', $boutique)" icone="modifier" :intitule="$b['modifier_court'].' '.$boutique->reference()" :infobulle="$b['modifier_court']" />
                                            @endif
                                            <form method="POST" action="{{ route('admin.referencement.boutiques.destroy', $boutique) }}">
                                                @csrf @method('DELETE')
                                                <x-admin.bouton-icone type="submit" icone="supprimer" variante="danger" :intitule="$b['supprimer'].' '.$boutique->reference()" :disabled="$motif !== null"
                                                                      :infobulle="$motif ?? $b['supprimer']" />
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $boutiques->links() }}</div>
        @endif
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
