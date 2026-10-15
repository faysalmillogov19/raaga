@php
    use App\Enums\CanalPaiement;

    $c = __('admin.boutiques.champs');
    $k = __('admin.boutiques.categorie');
    $titre = __('admin.boutiques.formulaire.'.$mode);
    $dateHeure = fn ($d) => $d ? \Carbon\Carbon::parse($d)->format('Y-m-d\TH:i') : null;
    $fil = [['libelle' => __('admin.boutiques.titre'), 'url' => route('admin.boutiques')]];
    if ($campagne->exists) {
        $fil[] = ['libelle' => $campagne->nom, 'url' => route('admin.boutiques.show', $campagne)];
    }
    $fil[] = ['libelle' => $titre];
@endphp

<x-admin.layout :titre="$titre" :fil="$fil">
    <form method="POST" action="{{ $campagne->exists ? route('admin.boutiques.update', $campagne) : route('admin.boutiques.store') }}" class="space-y-[18px]" novalidate>
        @csrf
        @if ($campagne->exists)
            @method('PUT')
        @endif

        <section class="bo-carte !mt-0" aria-labelledby="s-campagne">
            <h2 id="s-campagne" class="!text-[1.15rem]">{{ __('admin.boutiques.sections.campagne') }}</h2>
            <div class="mt-3 grid gap-4 lg:grid-cols-2">
                <x-admin.champ nom="nom" :libelle="ucfirst($c['nom'])" :valeur="$campagne->nom" required maxlength="150" class="lg:col-span-2" />
                <div class="formulaire-champ !mt-0 lg:col-span-2">
                    <label for="description">{{ ucfirst($c['description']) }}</label>
                    <textarea id="description" name="description" rows="3" maxlength="2000" class="champ @error('description') est-invalide @enderror">{{ old('description', $campagne->description) }}</textarea>
                    @error('description') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
                </div>
                <x-admin.champ nom="date_ouverture" :libelle="ucfirst($c['date_ouverture'])" type="datetime-local" :valeur="$dateHeure($campagne->date_ouverture)" required />
                <x-admin.champ nom="date_cloture" :libelle="ucfirst($c['date_cloture'])" type="datetime-local" :valeur="$dateHeure($campagne->date_cloture)" required />
            </div>
        </section>

        {{-- Lots : types proposés par site confirmé ; lots homogènes et montants issus de la grille tarifaire --}}
        <section class="bo-carte !mt-0" aria-labelledby="s-lots">
            <h2 id="s-lots" class="!text-[1.15rem]">{{ $k['titre'] }}</h2>
            <p class="texte-carte mt-1">{{ $k['aide'] }}</p>
            @error('lots') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror

            @forelse ($sites as $site)
                <fieldset class="mt-4 rounded-charte border border-ligne p-3">
                    <legend class="px-1 font-bold text-encre">{{ $site->nom }} <span class="texte-carte font-normal">· {{ $site->localite() }}@if ($site->arrondissement) · {{ $site->arrondissement }}@endif</span></legend>
                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach ($disponibilites[$site->id] as $typeId => $nombre)
                            @php($id = 'lot-'.$site->id.'-'.$typeId)
                            <label for="{{ $id }}" class="flex items-start gap-2">
                                <input type="hidden" name="lots[{{ $site->id }}][{{ $typeId }}]" value="0">
                                <input id="{{ $id }}" type="checkbox" name="lots[{{ $site->id }}][{{ $typeId }}]" value="1" class="mt-1"
                                       @checked(old('lots.'.$site->id.'.'.$typeId, $selection[$site->id][$typeId] ?? false))>
                                <span>{{ $types[$typeId]->libelle }} <span class="texte-carte">({{ trans_choice('admin.boutiques.categorie.disponibles', $nombre, ['n' => format_nombre($nombre)]) }})</span></span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>
            @empty
                <x-admin.message type="erreur" class="mt-4">{{ $k['aucun_site'] }} <a href="{{ route('admin.sites') }}">{{ __('admin.sites.titre') }}</a></x-admin.message>
            @endforelse
        </section>

        <section class="bo-carte !mt-0" aria-labelledby="s-paiement">
            <h2 id="s-paiement" class="!text-[1.15rem]">{{ __('admin.boutiques.sections.paiement') }}</h2>
            <div class="mt-3 grid gap-4 lg:grid-cols-2">
                <x-admin.cases nom="canaux_actifs" :libelle="ucfirst($c['canaux_actifs'])"
                               :options="collect(CanalPaiement::cases())->mapWithKeys(fn ($e) => [$e->value => $e->label()])->all()"
                               :valeurs="$campagne->canaux_actifs ?? []" class="lg:col-span-2" />
                <x-admin.champ nom="delai_paiement_heures" :libelle="ucfirst($c['delai_paiement_heures'])" type="number" min="1" max="720" :valeur="$campagne->delai_paiement_heures" required />
                <x-admin.champ nom="max_boutiques_par_personne" :libelle="ucfirst($c['max_boutiques_par_personne'])" type="number" min="1"
                               :valeur="$campagne->max_boutiques_par_personne" :aide="$k['sans_plafond']" />
            </div>
        </section>

        <div class="flex gap-3">
            <x-admin.circuit />
            <x-admin.bouton variante="contour" href="{{ $campagne->exists ? route('admin.boutiques.show', $campagne) : route('admin.boutiques') }}">{{ __('admin.tranches.formulaire.annuler') }}</x-admin.bouton>
        </div>
    </form>
</x-admin.layout>
