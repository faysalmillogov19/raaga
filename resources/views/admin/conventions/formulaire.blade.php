@php
    use App\Enums\ModeInscriptionA;

    $c = __('institutionnel.bo.conventions');
    $champs = $c['champs'];
    $titre = $c['formulaire'][$mode];
    $action = match ($mode) {
        'creation' => route('admin.conventions.store'),
        'modification' => route('admin.conventions.update', $convention),
        'avenant' => route('admin.conventions.avenant.store', $convention),
    };
    $lignes = array_values(old('echeancier', array_map(fn ($l) => [
        'libelle' => $l['libelle'], 'echeance' => $l['echeance'], 'type' => $l['type'], 'valeur' => $l['valeur'],
    ], $convention->echeancier ?? [])));
    $fil = [['libelle' => $c['titre'], 'url' => route('admin.conventions')]];
    if ($convention->exists) {
        $fil[] = ['libelle' => $convention->reference, 'url' => route('admin.conventions.show', $convention)];
    }
    $fil[] = ['libelle' => $titre];
@endphp

<x-admin.layout :titre="$titre" :fil="$fil">
    <form method="POST" action="{{ $action }}" enctype="multipart/form-data" class="space-y-[18px]" novalidate
          x-data="{
              lignes: @js($lignes),
              engage: @js((int) old('montant_engage', $convention->montant_engage)),
              montant(l) { return l.type === 'pourcentage' ? Math.floor(this.engage * Math.round(parseFloat(l.valeur || 0) * 100) / 10000) : (parseInt(l.valeur) || 0) },
              total() { return this.lignes.reduce((s, l) => s + this.montant(l), 0) },
              fcfa(n) { return new Intl.NumberFormat('fr-FR').format(n || 0).replace(/\s/g, ' ') + ' FCFA' },
          }">
        @csrf
        @if ($mode === 'modification')
            @method('PUT')
        @endif

        @if ($mode === 'avenant')
            <div class="bo-carte !mt-0">
                <div class="formulaire-champ !mt-0">
                    <label for="motif"><x-requis /> {{ __('admin.parametrage.actions.motif') }}</label>
                    <textarea id="motif" name="motif" rows="2" required class="champ @error('motif') est-invalide @enderror">{{ old('motif') }}</textarea>
                    @error('motif') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
                </div>
            </div>
        @endif

        <section class="bo-carte !mt-0 grid gap-4 lg:grid-cols-2">
            @if ($mode === 'creation')
                <x-admin.liste nom="institution_id" :libelle="$champs['institution_id']" :options="$institutions->mapWithKeys(fn ($i) => [$i->id => $i->denomination])->all()" :valeur="$convention->institution_id" vide="—" />
            @else
                <div class="formulaire-champ !mt-0"><span class="font-bold text-encre">{{ $champs['institution_id'] }}</span><p>{{ $convention->institution->denomination }}</p></div>
            @endif
            <x-admin.champ nom="reference" :libelle="$champs['reference']" :valeur="$convention->reference" required maxlength="60" />
            <x-admin.champ nom="nb_actions" :libelle="$champs['nb_actions']" type="number" min="1" :valeur="$convention->nb_actions" required />
            <x-admin.champ nom="prix_unitaire" :libelle="$champs['prix_unitaire']" type="number" min="1" :valeur="$convention->prix_unitaire" required />
            <div class="formulaire-champ !mt-0">
                <label for="montant_engage"><x-requis /> {{ $champs['montant_engage'] }}</label>
                <input id="montant_engage" name="montant_engage" type="number" min="1" required x-model.number="engage" class="champ @error('montant_engage') est-invalide @enderror">
                @error('montant_engage') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
            </div>
            <x-admin.liste nom="mode_inscription" :libelle="$champs['mode_inscription']" :options="collect(ModeInscriptionA::cases())->mapWithKeys(fn ($m) => [$m->value => $m->label()])->all()" :valeur="$convention->mode_inscription" />
            <div class="formulaire-champ !mt-0 lg:col-span-2">
                <label for="droits_particuliers">{{ $champs['droits_particuliers'] }}</label>
                <textarea id="droits_particuliers" name="droits_particuliers" rows="3" class="champ">{{ old('droits_particuliers', $convention->droits_particuliers) }}</textarea>
            </div>
                <x-admin.depot-fichier nom="pdf" id="pdf" :libelle="$champs['pdf']" formats=".pdf" accept="application/pdf" :taille-mo="10" class="lg:col-span-2"
                                       :aide="$convention->pdf_nom_original ?: null" />
        </section>

        <section class="bo-carte" aria-labelledby="echeancier-titre">
            <h2 id="echeancier-titre" class="!text-[1.15rem]">{{ $champs['echeancier'] }}</h2>
            <div class="mt-3 overflow-x-auto">
                <table class="bo-tableau">
                    <thead><tr><th scope="col">{{ $c['libelle'] }}</th><th scope="col">{{ $c['echeance'] }}</th><th scope="col">{{ $c['type'] }}</th><th scope="col">{{ $c['valeur'] }}</th><th scope="col" class="montant">{{ $c['montant_calcule'] }}</th><th></th></tr></thead>
                    <tbody>
                        <template x-for="(ligne, i) in lignes" :key="i">
                            <tr>
                                <td><input class="champ" :name="`echeancier[${i}][libelle]`" x-model="ligne.libelle" required maxlength="160" aria-label="{{ $c['libelle'] }}"></td>
                                <td><input class="champ" type="date" :name="`echeancier[${i}][echeance]`" x-model="ligne.echeance" required aria-label="{{ $c['echeance'] }}"></td>
                                <td><select class="champ" :name="`echeancier[${i}][type]`" x-model="ligne.type" aria-label="{{ $c['type'] }}">
                                    @foreach ($c['types'] as $valeur => $libelle)<option value="{{ $valeur }}">{{ $libelle }}</option>@endforeach
                                </select></td>
                                <td><input class="champ w-40" type="number" step="any" min="0" :name="`echeancier[${i}][valeur]`" x-model="ligne.valeur" required aria-label="{{ $c['valeur'] }}"></td>
                                <td class="montant" x-text="fcfa(montant(ligne))"></td>
                                <td><button type="button" class="lien-action" @click="lignes.splice(i, 1)">{{ $c['retirer'] }}</button></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <x-admin.bouton variante="contour" class="mt-3" type="button"  x-on:click="lignes.push({ libelle: '', echeance: '', type: 'pourcentage', valeur: '' })">{{ $c['ajouter'] }}</x-admin.bouton>
            <p class="mt-3 font-semibold" :class="total() === engage ? 'text-vert-fonce' : 'text-rouge'"
               x-text="@js(__('institutionnel.bo.conventions.total', ['total' => '__T__', 'engage' => '__E__'])).replace('__T__', fcfa(total())).replace('__E__', fcfa(engage))"></p>
            @error('echeancier') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
            @foreach ($errors->get('echeancier.*') as $messages)
                @foreach ($messages as $m)<p class="message-erreur" role="alert">{{ $m }}</p>@endforeach
            @endforeach
        </section>

        <div class="flex gap-3">
            <x-admin.bouton variante="principal" type="submit">{{ $c['enregistrer'] }}</x-admin.bouton>
            <x-admin.bouton variante="contour" href="{{ $convention->exists ? route('admin.conventions.show', $convention) : route('admin.conventions') }}">{{ __('admin.confirmation.annuler') }}</x-admin.bouton>
        </div>
    </form>
</x-admin.layout>
