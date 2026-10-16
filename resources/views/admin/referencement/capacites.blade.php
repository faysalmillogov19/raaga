@php
    use App\Enums\Droit;

    $k = __('admin.referencement.capacites');
    $z = __('admin.zones');
    $gerer = auth()->user()->can(Droit::SitesGerer->value);
    $libelleSite = fn ($s) => ($s->code ? $s->code.' · ' : '').$s->nom;
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

        <p class="texte-carte mt-1">{{ $k['liste_aide'] }}</p>

        {{-- Tableau : filtre par site (retenu pendant la session), recherche et tri. --}}
        <x-tableau :t="$tableau" :libelle="$k['liste_titre']" class="mt-4" />
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
