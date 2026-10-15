@php
    use App\Enums\Droit;

    $k = __('admin.referencement.capacites');
    $z = __('admin.zones');
    $gerer = auth()->user()->can(Droit::SitesGerer->value);
    $surface = fn (?float $s) => $s === null ? '—' : format_nombre($s, fmod($s, 1.0) == 0.0 ? 0 : 2);
    $libelleSite = fn ($s) => ($s->code ? $s->code.' · ' : '').$s->nom;
    // Colonnes filtrables : clé => [libellé, type de filtre].
    $colonnes = [
        'site' => [$k['colonnes']['site'], 'texte'],
        'niveau' => [$k['colonnes']['niveau'], 'texte'],
        'zone' => [$k['colonnes']['zone'], 'texte'],
        'type' => [$k['colonnes']['type'], 'texte'],
        'nombre' => [$k['colonnes']['nombre'], 'texte'],
        'unitaire' => [$k['colonnes']['surface_unitaire'], 'texte'],
        'retenue' => [$k['colonnes']['surface_retenue'], 'texte'],
        'etat' => [$k['colonnes']['etat'], 'etat'],
        'source' => [$k['colonnes']['source'], 'texte'],
    ];
    $initial = collect($colonnes)->map(fn () => '')->put('site', $filtreSite ? $libelleSite($filtreSite) : '');
@endphp

<x-admin.layout :titre="$k['titre']" :fil="[['libelle' => $k['titre']]]">
    <div class="bo-carte !mt-0">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="!text-[1.15rem]">{{ $k['liste_titre'] }}</h2>
            </div>
            @if ($gerer)
                <div class="flex flex-wrap gap-3">
                    <x-admin.bouton variante="contour" href="{{ route('admin.referencement.imports') }}">{{ $k['importer'] }}</x-admin.bouton>
                    {{-- Ajout d'une capacité en fenêtre modale (site en brouillon ou en révision). --}}
                    <x-admin.fenetre id="ajouter-capacite" :titre="$k['ajouter']" :action="route('admin.referencement.capacites.store')" :bouton="$k['ajouter']" declencheur-icone="plus">
                        <x-slot:declencheur>{{ $k['ajouter'] }}</x-slot:declencheur>
                        @if ($sitesModifiables->isEmpty())
                            <x-admin.message type="avertissement">{{ $k['aucun_site_modifiable'] }}</x-admin.message>
                        @else
                            <div class="adm-fenetre-grille">
                                <x-admin.champ nom="site_id" id="cap-site" :libelle="ucfirst($k['champs']['site_id'])" type="liste" required class="adm-plein"
                                               :options="$sitesModifiables->mapWithKeys(fn ($s) => [$s->id => $libelleSite($s)])->all()" :valeur="$filtreSite?->id" :vide="$k['choisir_option']" />
                                @include('admin.referencement._capacite-champs', ['capacite' => null])
                            </div>
                        @endif
                    </x-admin.fenetre>
                </div>
            @endif
        </div>


        {{-- Tableau : un champ de recherche par colonne (filtrage dans la page). --}}
        <div class="mt-4 overflow-x-auto"
             x-data="{ f: @js($initial),
                       voit(tr) { return Object.entries(this.f).every(([c, v]) => ! v || (tr.dataset[c] || '').toLowerCase().includes(String(v).toLowerCase())) },
                       aucun() { return [...this.$root.querySelectorAll('tr[data-ligne]')].every(tr => ! this.voit(tr)) } }">
            <table class="bo-tableau">
                <thead>
                    <tr>
                        @foreach ($colonnes as $cle => [$libelle])
                            <th scope="col" @class(['montant' => in_array($cle, ['nombre', 'unitaire', 'retenue'], true)])>{{ $libelle }}</th>
                        @endforeach
                        <th scope="col">{{ $k['colonnes']['actions'] }}</th>
                    </tr>
                    <tr>
                        @foreach ($colonnes as $cle => [$libelle, $filtre])
                            <td>
                                <label for="filtre-{{ $cle }}" class="sr-only">{{ __('admin.zones.filtrer', ['colonne' => $libelle]) }}</label>
                                @if ($filtre === 'etat')
                                    <select id="filtre-{{ $cle }}" x-model="f.{{ $cle }}" class="champ !py-1 text-sm">
                                        <option value="">{{ $z['tous'] }}</option>
                                        <option value="{{ $k['complete'] }}">{{ $k['complete'] }}</option>
                                        <option value="{{ $k['a_completer'] }}">{{ $k['a_completer'] }}</option>
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
                    @foreach ($toutes as $cap)
                        @php
                            $niveau = $cap->niveau ? $cap->niveau.' · '.($cap->libelleNiveau() ?? '') : $k['tous_niveaux'];
                            $type = ($cap->type->code ? $cap->type->code.' · ' : '').$cap->type->libelle;
                            $etat = $cap->estIncomplete() ? $k['a_completer'] : $k['complete'];
                            $unitaire = $surface($cap->surface_unitaire !== null ? (float) $cap->surface_unitaire : null);
                            $retenue = $surface($cap->surfaceRetenue());
                            $modifiable = $cap->site->estModifiable();
                            $nom = $libelleSite($cap->site).' · '.$niveau.' · '.$type;
                        @endphp
                        <tr x-show="voit($el)" data-ligne
                            data-site="{{ $libelleSite($cap->site) }}" data-niveau="{{ $niveau }}" data-zone="{{ $cap->zone ?? '' }}" data-type="{{ $type }}"
                            data-nombre="{{ $cap->nombre_prevu ?? '' }}" data-unitaire="{{ $unitaire }}" data-retenue="{{ $retenue }}" data-etat="{{ $etat }}"
                            data-source="{{ $cap->source ?? '' }}">
                            <td>{{ $libelleSite($cap->site) }}</td>
                            <td>{{ $niveau }}</td>
                            <td>{{ $cap->zone ?? '—' }}</td>
                            <td>{{ $type }}</td>
                            <td class="montant">{{ $cap->nombre_prevu !== null ? format_nombre($cap->nombre_prevu) : '—' }}</td>
                            <td class="montant">{{ $unitaire }}</td>
                            <td class="montant">{{ $retenue }}</td>
                            <td><span @class(['bo-etiquette', 'bo-etiquette-succes' => ! $cap->estIncomplete(), 'bo-etiquette-attention' => $cap->estIncomplete()])>{{ $etat }}</span></td>
                            <td>{{ $cap->source ?? '—' }}@if ($cap->observations)<br><span class="texte-carte">{{ $cap->observations }}</span>@endif</td>
                            <td>
                                <div class="flex gap-2">
                                    <x-admin.bouton-icone :href="route('admin.referencement.boutiques', array_filter(['site' => $cap->site_id, 'niveau' => $cap->niveau, 'zone' => $cap->zone, 'type' => $cap->type_boutique_id]))"
                                                          icone="voir" :intitule="$k['voir'].' : '.$nom" :infobulle="$k['voir']" />
                                    @if ($gerer)
                                        @if ($modifiable)
                                            <x-admin.bouton-icone :href="route('admin.referencement.capacites.edit', $cap)" :data-fenetre-url="route('admin.referencement.capacites.edit', $cap)" aria-haspopup="dialog" id="modifier-capacite-{{ $cap->id }}-ouvrir" icone="modifier" :intitule="$k['modifier'].' : '.$nom" :infobulle="$k['modifier']" />
                                        @else
                                            <x-admin.bouton-icone icone="modifier" :intitule="$k['modifier'].' : '.$nom" :infobulle="$k['site_fige']" disabled />
                                        @endif
                                        @if ($modifiable)
                                            <x-admin.confirmation :id="'supprimer-capacite-'.$cap->id" taille="petit" danger :titre="$k['supprimer'].' : '.$nom" :message="$k['confirmer_suppression']"
                                                                  :action="route('admin.referencement.capacites.destroy', $cap)" methode="DELETE" :bouton="$k['supprimer']"
                                                                  icone="supprimer" :libelle="$k['supprimer'].' : '.$nom" />
                                        @else
                                            <x-admin.bouton-icone icone="supprimer" variante="danger" :intitule="$k['supprimer'].' : '.$nom" :infobulle="$k['site_fige']" disabled />
                                        @endif
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    <tr x-show="aucun()" x-cloak><td colspan="{{ count($colonnes) + 1 }}">{{ $toutes->isEmpty() ? $k['aucune'] : $z['aucun_resultat'] }}</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    @if ($capaciteModifiee)
        @include('admin.referencement._fenetre-capacite', ['capacite' => $capaciteModifiee, 'listes' => $listesModifiee, 'ouvrir' => true])
    @endif

    {{-- Comparaison avec le référentiel, pour le site choisi. --}}
    @if ($sites->isNotEmpty())
        <div class="bo-carte">
            <h2 class="!text-[1.15rem]">{{ $k['comparaison_titre'] }}</h2>
            <form method="GET" action="{{ route('admin.referencement.capacites') }}" class="mt-3 flex flex-wrap items-end gap-3">
                <div class="formulaire-champ !mt-0">
                    <label for="site">{{ $k['choisir'] }}</label>
                    <select id="site" name="site" class="champ">
                        @foreach ($sites as $s)
                            <option value="{{ $s->id }}" @selected($site?->id === $s->id)>{{ $libelleSite($s) }} ({{ trans_choice('admin.zones.capacites', $s->capacites_count, ['n' => format_nombre($s->capacites_count)]) }})</option>
                        @endforeach
                    </select>
                </div>
                <x-admin.bouton variante="contour" type="submit">{{ $k['afficher'] }}</x-admin.bouton>
            </form>
        </div>
        @if ($site)
            @include('admin.sites._capacites', ['site' => $site, 'capacites' => collect(), 'comparaison' => $comparaison])
        @endif
    @else
        <x-etat-vide icone="info" :titre="$k['vide_titre']" :lien="$gerer ? route('admin.referencement.imports') : null" :libelle-lien="$k['importer']">{{ $k['vide'] }}</x-etat-vide>
    @endif
</x-admin.layout>
