@php
    use App\Enums\EtatPhysiqueBoutique;
    use App\Models\Niveau;
    use App\Enums\StatutBoutique;

    $b = __('admin.referencement.boutiques');
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
        <p class="texte-carte max-w-3xl">{{ $b['intro'] }}</p>

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
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $boutiques->links() }}</div>
        @endif
    </div>
</x-admin.layout>
