@php
    use App\Enums\Droit;
    use App\Enums\StatutTypeBoutique;

    $z = __('admin.zones');
    $niveaux = $liste === 'niveaux';
    $gerer = auth()->user()->can(Droit::SitesGerer->value);
    $titre = $z[$liste.'_titre'];
    // Colonnes filtrables : clé => [libellé, type de filtre].
    $colonnes = array_filter([
        'code' => [$z['colonnes']['code'], 'texte'],
        'libelle' => [$z['colonnes']['libelle'], 'texte'],
        'description' => $niveaux ? null : [$z['colonnes']['description'], 'texte'],
        'rang' => [$z['colonnes'][$niveaux ? 'rang' : 'ordre'], 'texte'],
        'statut' => [$z['colonnes']['statut'], 'statut'],
        'defaut' => [$z['colonnes']['par_defaut'], 'oui_non'],
        'utilise' => [$z['colonnes']['utilisation'], 'texte'],
    ]);
@endphp

<x-admin.layout :titre="$titre" :fil="[['libelle' => $titre]]">
    <div class="bo-carte !mt-0">
        <p class="texte-carte max-w-3xl">{{ $z[$liste.'_aide'] }} {{ $z['regles_communes'] }}</p>

        @if ($gerer)
            <details class="mt-4 rounded-charte border border-ligne p-4" @if ($errors->any()) open @endif>
                <summary class="cursor-pointer font-semibold text-vert-fonce">{{ $z[$liste.'_ajouter'] }}</summary>
                <form method="POST" action="{{ route('admin.referencement.zones.store', $liste) }}" class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-4" novalidate>
                    @csrf
                    <x-admin.champ nom="code" :libelle="$z['colonnes']['code']" :maxlength="$niveaux ? 3 : 5" required autocomplete="off" :aide="$z[$liste.'_code_aide']" />
                    <x-admin.champ nom="libelle" :libelle="$z['colonnes']['libelle']" maxlength="100" required />
                    @if ($niveaux)
                        <x-admin.champ nom="rang" :libelle="$z['colonnes']['rang']" type="number" min="-9" max="99" required :aide="$z['rang_aide']" />
                    @else
                        <x-admin.champ nom="description" :libelle="$z['colonnes']['description']" maxlength="300" />
                        <x-admin.champ nom="ordre" :libelle="$z['colonnes']['ordre']" type="number" min="0" max="9999" />
                    @endif
                    <div class="flex items-end"><button type="submit" class="bo-bouton bo-bouton-principal">{{ $z['ajouter'] }}</button></div>
                </form>
            </details>
        @endif

        {{-- Tableau : un champ de recherche par colonne (filtrage dans la page). --}}
        <div class="mt-4 overflow-x-auto"
             x-data="{ f: { {{ collect($colonnes)->keys()->map(fn ($c) => $c.": ''")->implode(', ') }} },
                       voit(tr) { return Object.entries(this.f).every(([k, v]) => ! v || (tr.dataset[k] || '').toLowerCase().includes(String(v).toLowerCase())) },
                       aucun() { return [...this.$root.querySelectorAll('tr[data-code]')].every(tr => ! this.voit(tr)) } }">
            <table class="bo-tableau">
                <thead>
                    <tr>
                        @foreach ($colonnes as $cle => [$libelle])
                            <th scope="col" @class(['montant' => in_array($cle, ['rang', 'utilise'], true)])>{{ $libelle }}</th>
                        @endforeach
                        <th scope="col">{{ $z['colonnes']['actions'] }}</th>
                    </tr>
                    <tr>
                        @foreach ($colonnes as $cle => [$libelle, $filtre])
                            <td>
                                <label for="filtre-{{ $cle }}" class="sr-only">{{ __('admin.zones.filtrer', ['colonne' => $libelle]) }}</label>
                                @if ($filtre === 'statut')
                                    <select id="filtre-{{ $cle }}" x-model="f.{{ $cle }}" class="champ !py-1 text-sm">
                                        <option value="">{{ $z['tous'] }}</option>
                                        @foreach (StatutTypeBoutique::cases() as $s)<option value="{{ $s->label() }}">{{ $s->label() }}</option>@endforeach
                                        <option value="{{ $z['desactive'] }}">{{ $z['desactive'] }}</option>
                                    </select>
                                @elseif ($filtre === 'oui_non')
                                    <select id="filtre-{{ $cle }}" x-model="f.{{ $cle }}" class="champ !py-1 text-sm">
                                        <option value="">{{ $z['tous'] }}</option>
                                        <option value="{{ $z['oui'] }}">{{ $z['oui'] }}</option>
                                        <option value="{{ $z['non'] }}">{{ $z['non'] }}</option>
                                    </select>
                                @else
                                    <input id="filtre-{{ $cle }}" x-model="f.{{ $cle }}" type="search" class="champ !py-1 text-sm" placeholder="{{ $z['rechercher'] }}" autocomplete="off">
                                @endif
                            </td>
                        @endforeach
                        <td></td>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($elements as $el)
                        @php
                            $statut = $el->statut->label().($el->actif ? '' : ' · '.$z['desactive']);
                            $supprimable = ! $el->par_defaut && $el->nombreUtilisations() === 0;
                        @endphp
                        <tr x-show="voit($el)" @class(['opacity-70' => ! $el->actif])
                            data-code="{{ $el->code }}" data-libelle="{{ $el->libelle }}" data-description="{{ $el->description ?? '' }}"
                            data-rang="{{ $niveaux ? $el->rang : $el->ordre }}" data-statut="{{ $statut }}"
                            data-defaut="{{ $el->par_defaut ? $z['oui'] : $z['non'] }}" data-utilise="{{ $el->boutiques_count }}">
                            <td class="font-mono font-semibold">{{ $el->code }}</td>
                            <td>{{ $el->libelle }}</td>
                            @unless ($niveaux)<td>{{ $el->description ?? '—' }}</td>@endunless
                            <td class="montant">{{ $niveaux ? $el->rang : $el->ordre }}</td>
                            <td>
                                <span @class(['bo-etiquette', 'bo-etiquette-succes' => $el->statut === StatutTypeBoutique::Valide, 'bo-etiquette-attention' => $el->statut === StatutTypeBoutique::AValider, 'bo-etiquette-echec' => $el->statut === StatutTypeBoutique::Rejete])>{{ $el->statut->label() }}</span>
                                @unless ($el->actif)<span class="bo-etiquette bo-etiquette-neutre">{{ $z['desactive'] }}</span>@endunless
                            </td>
                            <td>@if ($el->par_defaut)<span class="bo-etiquette bo-etiquette-neutre">{{ $z['par_defaut'] }}</span>@else{{ $z['non'] }}@endif</td>
                            <td class="montant">{{ trans_choice('admin.zones.boutiques', $el->boutiques_count, ['n' => format_nombre($el->boutiques_count)]) }}</td>
                            <td>
                                <div class="flex flex-wrap gap-2">
                                    <a href="{{ route('admin.referencement.zones.show', [$liste, $el->id]) }}" class="bo-bouton bo-bouton-secondaire">{{ $gerer ? $z['modifier'] : $z['voir'] }}<span class="sr-only"> {{ $el->code }}</span></a>
                                    @if ($gerer)
                                        <form method="POST" action="{{ route('admin.referencement.zones.destroy', [$liste, $el->id]) }}">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="bo-bouton bo-bouton-danger" @disabled(! $supprimable)
                                                    title="{{ $supprimable ? '' : ($el->par_defaut ? $z['regles']['defaut'] : $z['regles']['utilisee']) }}">{{ $z['supprimer'] }}<span class="sr-only"> {{ $el->code }}</span></button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    <tr x-show="aucun()" x-cloak><td colspan="{{ count($colonnes) + 1 }}">{{ $z['aucun_resultat'] }}</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</x-admin.layout>
