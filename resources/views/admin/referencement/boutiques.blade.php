@php
    use App\Enums\Droit;

    $b = __('admin.referencement.boutiques');
    $f = __('admin.sites.boutiques');
    $x = __('admin.sites.excel');
    $gerer = auth()->user()->can(Droit::SitesGerer->value);
    $siteFiltre = (int) ($tableau->etat['filtres']['site'] ?? 0);
    $siteDefaut = $sitesModifiables->contains('id', $siteFiltre) ? $siteFiltre : null;
    $listeSites = $sitesModifiables->mapWithKeys(fn ($s) => [$s->id => $s->code.' · '.$s->nom])->all();
    $numeroMax = str_repeat('9', \App\Models\Boutique::CHIFFRES_NUMERO);
@endphp

<x-admin.layout :titre="$b['titre']" :sous-titre="$b['sous_titre']" :fil="[['libelle' => $b['titre']]]">
    <x-slot:actions>
        <x-admin.bouton variante="contour" icone="exporter" :href="$tableau->urlExport()" data-export-tableau="boutiques">{{ $b['exporter_court'] }}</x-admin.bouton>
        @if ($gerer)
            {{-- Import Excel : site choisi (brouillon ou révision), modèle, fichier ; export du référentiel d'un site. --}}
            <x-admin.fenetre id="importer-boutiques" :titre="$x['titre']" :action="route('admin.referencement.boutiques.excel.deposer')" :bouton="$x['importer']"
                             fichiers declencheur-variante="secondaire" declencheur-icone="importer">
                <x-slot:declencheur>{{ $b['importer'] }}</x-slot:declencheur>
                <p class="texte-carte mb-4">{{ $b['excel_import_aide'] }}</p>
                @if ($sitesModifiables->isEmpty())
                    <x-admin.message type="avertissement">{{ $b['aucun_site_modifiable'] }}</x-admin.message>
                @else
                    <x-admin.champ nom="site_id" id="import-site" :libelle="$b['choisir_site']" type="liste" :options="$listeSites" :valeur="$siteDefaut" :vide="$b['choisir']" required />
                    <x-admin.depot-fichier nom="fichier" id="import-fichier" class="mt-4" :libelle="$x['importer']" :taille-mo="config('raaga.referentiel_boutiques.taille_max_ko') / 1024" />
                    <div class="mt-4 flex flex-wrap gap-3">
                        <x-admin.bouton type="submit" variante="contour" taille="petit" icone="exporter" formmethod="GET" :formaction="route('admin.referencement.boutiques.excel.modele')"
                                        formnovalidate>{{ $x['modele'] }}</x-admin.bouton>
                        <x-admin.bouton type="submit" variante="contour" taille="petit" icone="exporter" formmethod="GET" :formaction="route('admin.referencement.boutiques.excel.export')"
                                        formnovalidate>{{ $b['exporter_site'] }}</x-admin.bouton>
                    </div>
                @endif
            </x-admin.fenetre>

            {{-- Ajout : une boutique ou une série numérotée, sur un site en brouillon ou en révision. --}}
            <x-admin.fenetre id="ajouter-boutiques" :titre="$b['ajouter']" :action="route('admin.referencement.boutiques.ajouter')" :bouton="$b['ajouter']"
                             declencheur-icone="plus" :ouvrir="request()->boolean('ajouter')">
                <x-slot:declencheur>{{ $b['ajouter'] }}</x-slot:declencheur>
                @if ($sitesModifiables->isEmpty())
                    <x-admin.message type="avertissement">{{ $b['aucun_site_modifiable'] }}</x-admin.message>
                @elseif ($types->isEmpty())
                    <x-admin.message type="avertissement">{{ __('admin.sites.types.aucun') }}</x-admin.message>
                @else
                    <div x-data="{ mode: @js(old('mode', 'une')) }" x-on:change="if ($event.target.name === 'mode') mode = $event.target.value">
                        <div class="adm-fenetre-grille">
                            <x-admin.champ nom="site_id" id="ajout-site" :libelle="$b['choisir_site']" type="liste" :options="$listeSites" :valeur="$siteDefaut" :vide="$b['choisir']" required class="adm-plein" />
                            <x-admin.champ nom="mode" :libelle="$b['mode']" type="radio" :options="['une' => $b['mode_une'], 'serie' => $b['mode_serie']]" valeur="une" class="adm-plein" />
                            @include('admin.sites._champs-code', ['prefixe' => 'ajout', 'boutique' => null])
                            <div class="formulaire-champ !mt-0" x-show="mode === 'une'">
                                <label for="ajout-numero">{{ $f['numero'] }}</label>
                                <input id="ajout-numero" name="numero" type="number" min="1" max="{{ $numeroMax }}" class="champ" x-bind:disabled="mode !== 'une'" value="{{ old('numero') }}">
                                <p class="aide-champ">{{ $f['numero_aide'] }}</p>
                                @error('numero') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
                            </div>
                            <div class="formulaire-champ !mt-0" x-show="mode === 'serie'" x-cloak>
                                <label for="ajout-debut">{{ $f['debut'] }}</label>
                                <input id="ajout-debut" name="debut" type="number" min="1" max="{{ $numeroMax }}" class="champ" x-bind:disabled="mode !== 'serie'" value="{{ old('debut', 1) }}">
                                @error('debut') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
                            </div>
                            <div class="formulaire-champ !mt-0" x-show="mode === 'serie'" x-cloak>
                                <label for="ajout-fin">{{ $f['fin'] }}</label>
                                <input id="ajout-fin" name="fin" type="number" min="1" max="{{ $numeroMax }}" class="champ" x-bind:disabled="mode !== 'serie'" value="{{ old('fin') }}">
                                @error('fin') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
                            </div>
                            @include('admin.sites._champs-fiche', ['prefixe' => 'ajout', 'boutique' => null])
                        </div>
                    </div>
                @endif
            </x-admin.fenetre>
        @endif
    </x-slot:actions>

    <div class="bo-carte !mt-0">
        <x-tableau :t="$tableau" :libelle="$b['titre']" />
    </div>
    <p class="texte-carte mt-3 text-[12px]">{{ $b['sans_montant'] }}</p>
</x-admin.layout>
