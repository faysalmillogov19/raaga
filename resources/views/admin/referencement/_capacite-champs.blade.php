{{-- Champs d'une capacité prévue. Variables : $k (libellés), $listes (niveaux, zones, types), $capacite (valeurs, ou null). --}}
@php($c = $k['champs'])
@php($px = $prefixe ?? 'cap')
<div class="formulaire-champ !mt-0">
    <label for="{{ $px }}-niveau">{{ ucfirst($c['niveau']) }}</label>
    <select id="{{ $px }}-niveau" name="niveau" class="champ @error('niveau') est-invalide @enderror">
        <option value="">{{ $k['tous_niveaux'] }}</option>
        @foreach ($listes['niveaux'] as $n)
            <option value="{{ $n->code }}" @selected(old('niveau', $capacite?->niveau) === $n->code)>{{ $n->code }} · {{ $n->libelle }}</option>
        @endforeach
    </select>
    @error('niveau') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
</div>
<div class="formulaire-champ !mt-0">
    <label for="{{ $px }}-zone">{{ ucfirst($c['zone']) }}</label>
    <select id="{{ $px }}-zone" name="zone" class="champ @error('zone') est-invalide @enderror">
        <option value="">{{ $k['toutes_zones'] }}</option>
        @foreach ($listes['zones'] as $z)
            <option value="{{ $z->code }}" @selected(old('zone', $capacite?->zone) === $z->code)>{{ $z->code }} · {{ $z->libelle }}</option>
        @endforeach
    </select>
    @error('zone') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
</div>
<div class="formulaire-champ !mt-0">
    <label for="{{ $px }}-type"><x-requis /> {{ ucfirst($c['type_boutique_id']) }}</label>
    <select id="{{ $px }}-type" name="type_boutique_id" class="champ @error('type_boutique_id') est-invalide @enderror" required>
        <option value="">{{ $k['choisir_option'] }}</option>
        @foreach ($listes['types'] as $t)
            <option value="{{ $t->id }}" @selected((int) old('type_boutique_id', $capacite?->type_boutique_id) === $t->id)>{{ $t->code ? $t->code.' · ' : '' }}{{ $t->libelle }}</option>
        @endforeach
    </select>
    @error('type_boutique_id') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
</div>
<x-admin.champ nom="nombre_prevu" :id="$px.'-nombre_prevu'" :libelle="ucfirst($c['nombre_prevu'])" type="number" min="0" max="99999" :valeur="$capacite?->nombre_prevu" />
<x-admin.champ nom="surface_unitaire" :id="$px.'-surface_unitaire'" :libelle="ucfirst($c['surface_unitaire'])" type="number" step="0.01" min="0.01" :valeur="$capacite?->surface_unitaire" />
<x-admin.champ nom="surface_totale" :id="$px.'-surface_totale'" :libelle="ucfirst($c['surface_totale'])" type="number" step="0.01" min="0.01" :valeur="$capacite?->surface_totale" />
<x-admin.champ nom="source" :id="$px.'-source'" :libelle="ucfirst($c['source'])" maxlength="200" :valeur="$capacite?->source" />
<x-admin.champ nom="observations" :id="$px.'-observations'" :libelle="ucfirst($c['observations'])" maxlength="500" :valeur="$capacite?->observations" class="lg:col-span-2" />
