@php
    use App\Models\CampagneBoutiques;
    use App\Models\MoyenPaiement;

    $c = __('admin.boutiques.champs');
    $titre = __('admin.boutiques.formulaire.'.$mode);
    $dateHeure = fn ($d) => $d ? \Carbon\Carbon::parse($d)->format('Y-m-d\TH:i') : null;
    $fil = [['libelle' => __('admin.boutiques.titre'), 'url' => route('admin.boutiques')]];
    if ($campagne->exists) {
        $fil[] = ['libelle' => $campagne->nom, 'url' => route('admin.boutiques.show', $campagne)];
    }
    $fil[] = ['libelle' => $titre];
    // Avenant d'une campagne ouverte : nom et ouverture figés, périmètre élargi seulement.
    $ouverte = $mode === 'avenant' && $campagne->statut === \App\Enums\StatutCampagneBoutiques::Ouverte;
    $action = match ($mode) {
        'avenant' => route('admin.boutiques.avenant.store', $campagne),
        'modification' => route('admin.boutiques.update', $campagne),
        default => route('admin.boutiques.store'),
    };
@endphp

<x-admin.layout :titre="$titre" :fil="$fil">
    <form method="POST" action="{{ $action }}" class="space-y-[18px]" novalidate>
        @csrf
        @if ($mode === 'modification')
            @method('PUT')
        @endif

        @if ($mode === 'avenant')
            {{-- Avenant : motif obligatoire ; le VALIDATEUR confirme ou rejette, rien ne change avant. --}}
            <div class="bo-carte !mt-0" data-avenant-campagne>
                <p>{{ $ouverte ? __('admin.boutiques.avenant.intro_ouverte') : __('admin.boutiques.avenant.intro_confirmee') }}</p>
                <div class="formulaire-champ mt-4">
                    <label for="motif"><x-requis /> {{ __('admin.parametrage.actions.motif') }}</label>
                    <textarea id="motif" name="motif" rows="2" required class="champ @error('motif') est-invalide @enderror">{{ old('motif') }}</textarea>
                    @error('motif') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
                </div>
            </div>
        @endif

        <section class="bo-carte !mt-0" aria-labelledby="s-campagne">
            <h2 id="s-campagne" class="!text-[1.15rem]">{{ __('admin.boutiques.sections.campagne') }}</h2>
            <div class="mt-3 grid gap-4 lg:grid-cols-2">
                @if ($ouverte)
                    <x-admin.champ nom="nom" :libelle="ucfirst($c['nom'])" type="verrouille" :valeur="$campagne->nom" class="lg:col-span-2" />
                    <input type="hidden" name="nom" value="{{ $campagne->nom }}">
                @else
                    <x-admin.champ nom="nom" :libelle="ucfirst($c['nom'])" :valeur="$campagne->nom" required maxlength="150" class="lg:col-span-2" />
                @endif
                {{-- Présentation : mise en forme simple (titres, listes, gras), aperçu rendu par le serveur (HTML neutralisé). --}}
                <div class="formulaire-champ !mt-0 lg:col-span-2" x-data="apercuTexte(@js(route('admin.boutiques.apercu')))" data-apercu-description>
                    <label for="description">{{ ucfirst($c['description']) }}</label>
                    <p id="description-aide" class="texte-carte">{{ __('admin.boutiques.aides.description') }}</p>
                    <div class="apercu-texte-grille mt-2">
                        <textarea id="description" name="description" rows="10" maxlength="2000" aria-describedby="description-aide"
                                  @input.debounce.400ms="maj($event.target.value)" class="champ @error('description') est-invalide @enderror">{{ old('description', $campagne->description) }}</textarea>
                        <div class="apercu-texte" aria-live="polite">
                            <p class="apercu-texte-titre">{{ __('admin.boutiques.aides.apercu') }}</p>
                            <div class="texte-riche" x-ref="rendu" data-apercu>
                                @if (filled(old('description', $campagne->description)))
                                    {{ \App\Support\MarkdownLeger::html(old('description', $campagne->description)) }}
                                @else
                                    <p class="texte-carte">{{ __('admin.boutiques.aides.apercu_vide') }}</p>
                                @endif
                            </div>
                        </div>
                    </div>
                    @error('description') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
                </div>
                @if ($ouverte)
                    <x-admin.champ nom="date_ouverture" :libelle="ucfirst($c['date_ouverture'])" type="verrouille" :valeur="$campagne->date_ouverture?->format('d/m/Y H:i')" />
                    <input type="hidden" name="date_ouverture" value="{{ $dateHeure($campagne->date_ouverture) }}">
                @else
                    <x-admin.champ nom="date_ouverture" :libelle="ucfirst($c['date_ouverture'])" type="datetime-local" :valeur="$dateHeure($campagne->date_ouverture)" required />
                @endif
                <x-admin.champ nom="date_cloture" :libelle="ucfirst($c['date_cloture'])" type="datetime-local" :valeur="$dateHeure($campagne->date_cloture)" required />
            </div>
        </section>

        {{-- Sites concernés : la campagne propose toutes leurs boutiques réservables, au premier arrivé ; montants issus de la grille tarifaire. --}}
        <section class="bo-carte !mt-0" aria-labelledby="s-sites">
            <h2 id="s-sites" class="!text-[1.15rem]">{{ __('admin.boutiques.sections.sites') }}</h2>
            <p class="texte-carte mt-1">{{ __('admin.boutiques.aides.sites') }}</p>
            @error('sites') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
            @if ($sitesDisponibles->isEmpty())
                <x-admin.message type="erreur" class="mt-4">{{ __('admin.boutiques.aucun_site') }} <a href="{{ route('admin.sites') }}">{{ __('admin.sites.titre') }}</a></x-admin.message>
            @else
                <x-admin.cases nom="sites" :libelle="ucfirst($c['sites'])" obligatoire class="mt-3"
                               :options="$sitesDisponibles->mapWithKeys(fn ($site) => [$site->id => ($site->code ? $site->code.' · ' : '').$site->nom])->all()"
                               :valeurs="array_map('strval', (array) ($campagne->sites ?? []))" />
            @endif

            {{-- Types proposés : actifs, validés et attribuables ; la chambre froide mutualisée (CF) n'est jamais proposée. --}}
            <h3 class="mt-6 !text-base" id="s-types">{{ __('admin.boutiques.sections.types') }}</h3>
            <p class="texte-carte mt-1">{{ __('admin.boutiques.aides.types') }}</p>
            @error('types') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
            @error('types.*') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
            {{-- Une ligne par type : case à cocher, puis nombre maximum d'emplacements de ce type par personne (1 par défaut, au moins 1). --}}
            <fieldset class="mt-3" data-types-campagne>
                <legend class="adm-libelle"><x-requis /> {{ ucfirst($c['types']) }}</legend>
                @error('limites.*') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
                <div class="mt-2 grid gap-2">
                    @foreach ($typesDisponibles as $t)
                        @php
                            $coche = in_array((string) $t->id, array_map('strval', (array) old('types', $campagne->types ?? [])), true);
                            $limite = old('limites.'.$t->id, $campagne->exists ? $campagne->limitePour($t->id) : CampagneBoutiques::LIMITE_PAR_TYPE);
                        @endphp
                        <div class="campagne-type-limite" data-type-limite="{{ $t->code }}">
                            <label class="adm-option">
                                <input type="checkbox" name="types[]" value="{{ $t->id }}" @checked($coche)>
                                <span>{{ ($t->code ? $t->code.' · ' : '').$t->libelle }}</span>
                            </label>
                            <label class="campagne-type-limite-champ">
                                <span>{{ $c['limite_par_type'] }}</span>
                                <input type="number" name="limites[{{ $t->id }}]" value="{{ $limite }}" min="1" max="1000" class="champ" aria-label="{{ $c['limite_par_type'] }} : {{ $t->code ?? $t->libelle }}">
                            </label>
                        </div>
                    @endforeach
                </div>
                <p class="texte-carte mt-2">{{ __('admin.boutiques.aides.limites') }}</p>
            </fieldset>
        </section>

        <section class="bo-carte !mt-0" aria-labelledby="s-paiement">
            <h2 id="s-paiement" class="!text-[1.15rem]">{{ __('admin.boutiques.sections.paiement') }}</h2>
            <div class="mt-3 grid gap-4 lg:grid-cols-2">
                <x-admin.cases nom="canaux_actifs" :libelle="ucfirst($c['canaux_actifs'])" obligatoire
                               :options="MoyenPaiement::proposables()->pluck('libelle', 'code')->all()"
                               :valeurs="$campagne->canaux_actifs ?? []" class="lg:col-span-2" />
                {{-- Délai de paiement de la caution : celui des « Délais de paiement » (RÉGULATEUR), sauf ancien délai propre à la campagne. --}}
                <div class="formulaire-champ adm-champ !mt-0" data-delai-caution>
                    <p class="adm-libelle">{{ ucfirst($c['delai_paiement']) }}</p>
                    <p class="font-semibold">{{ $campagne->libelleDelaiPaiement() }}</p>
                    <p class="texte-carte">{{ __($campagne->aSonPropreDelai() ? 'admin.boutiques.aides.delai_propre' : 'admin.boutiques.aides.delai_paiement') }}</p>
                </div>
            </div>
        </section>

        {{-- Listes d'attente : délai de relance de la liste générale (correction d'une identité : « Délais de paiement »). --}}
        <section class="bo-carte !mt-0" aria-labelledby="s-listes" data-delais-listes>
            <h2 id="s-listes" class="!text-[1.15rem]">{{ __('admin.boutiques.sections.listes') }}</h2>
            <div class="mt-3 grid gap-4 lg:grid-cols-2">
                @foreach (array_keys(CampagneBoutiques::DELAIS_LISTES) as $delai)
                    @php([$defaut, $dureeDefaut] = CampagneBoutiques::DELAIS_LISTES[$delai])
                    <fieldset class="adm-fenetre-grille" data-delai="{{ $delai }}">
                        <legend class="adm-plein adm-libelle">{{ ucfirst($c['delai_'.$delai]) }}</legend>
                        <x-admin.champ :nom="'delai_'.$delai.'_valeur'" :libelle="__('admin.delais.valeur')" type="number" min="1" max="999999"
                                       :valeur="$campagne->getAttribute('delai_'.$delai.'_valeur') ?? $defaut" />
                        <x-admin.champ :nom="'delai_'.$delai.'_duree'" :libelle="__('admin.delais.duree')" type="liste" :vide="__('admin.delais.choisir_duree')"
                                       :options="App\Models\Duree::toutes()->mapWithKeys(fn ($d) => [$d->code => ucfirst($d->libelle_pluriel)])->all()"
                                       :valeur="App\Models\Duree::parId($campagne->getAttribute('delai_'.$delai.'_duree_id'))?->code ?? $dureeDefaut" />
                        <p class="adm-plein texte-carte">{{ __('admin.boutiques.aides.delai_'.$delai) }}</p>
                    </fieldset>
                @endforeach
            </div>
        </section>

        <div class="flex flex-wrap gap-3">
            @if ($mode === 'avenant')
                <x-admin.bouton variante="principal" type="submit">{{ __('admin.boutiques.avenant.soumettre') }}</x-admin.bouton>
            @else
                <x-admin.enregistrer-soumettre />
            @endif
            <x-admin.bouton variante="contour" href="{{ $campagne->exists ? route('admin.boutiques.show', $campagne) : route('admin.boutiques') }}">{{ __('admin.tranches.formulaire.annuler') }}</x-admin.bouton>
        </div>
    </form>
</x-admin.layout>
