@props(['t', 'libelle' => null])

{{--
    Tableau de données traité côté serveur (App\Support\Tableau\Tableau) : « Afficher N lignes », recherche (300 ms),
    filtres, tri par en-tête, pagination, export du résultat filtré. L'état tient dans l'adresse. Avec JavaScript,
    seul le bloc des résultats est rechargé ; sans JavaScript, le formulaire et les liens fonctionnent normalement.
    Sous 768 px, chaque ligne devient une carte « libellé : valeur ».
--}}
@php($l = __('admin.donnees'))
<div {{ $attributes->merge(['class' => 'bo-donnees']) }} x-data="tableau" data-tableau="{{ $t->id }}" :aria-busy="charge ? 'true' : 'false'">
    <form method="GET" action="{{ request()->url() }}" x-ref="formulaire" @submit.prevent="soumettre()" class="bo-donnees-outils" role="search"
          aria-label="{{ $libelle ? $l['rechercher'].' : '.$libelle : $l['rechercher'] }}">
        <input type="hidden" name="tri" value="{{ $t->parametres()['tri'] ?? '' }}">
        <div class="bo-donnees-ligne">
            <label class="bo-donnees-lignes">
                <span>{{ $l['afficher'] }}</span>
                <select name="lignes" class="champ !w-auto !py-1" @change="soumettre()">
                    @foreach (\App\Support\Tableau\Tableau::LIGNES as $n)
                        <option value="{{ $n }}" @selected($t->etat['lignes'] === $n)>{{ $n }}</option>
                    @endforeach
                </select>
                <span>{{ $l['lignes'] }}</span>
            </label>
            @if ($t->aDesRecherchables())
                <label class="bo-donnees-recherche">
                    <span>{{ $l['rechercher'] }}</span>
                    <input type="search" name="q" value="{{ $t->etat['q'] }}" class="champ !py-1" autocomplete="off" maxlength="100" @input.debounce.300ms="soumettre()">
                </label>
            @endif
        </div>

        @if ($t->filtres)
            <div class="bo-donnees-filtres">
                @foreach ($t->filtres as $f)
                    <div class="formulaire-champ !mt-0">
                        <label for="{{ $t->id }}-{{ $f->cle }}">{{ $f->libelle }}</label>
                        <select id="{{ $t->id }}-{{ $f->cle }}" name="{{ $f->cle }}" class="champ !py-1" @change="soumettre()">
                            <option value="">{{ $l['tous'] }}</option>
                            @foreach ($f->options as $valeur => $texte)
                                <option value="{{ $valeur }}" @selected(($t->etat['filtres'][$f->cle] ?? null) === (string) $valeur)>{{ $texte }}</option>
                            @endforeach
                        </select>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Sans JavaScript : envoi manuel du formulaire. --}}
        <div class="flex flex-wrap items-center gap-3" x-show="false">
            <x-admin.bouton variante="contour" type="submit">{{ $l['appliquer'] }}</x-admin.bouton>
        </div>
    </form>

    <div class="relative">
        <div x-ref="resultats" @click="suivre($event)">
            @include('components.tableau-resultats', ['t' => $t])
        </div>
        <div class="bo-donnees-chargement" x-show="charge" x-cloak role="status">{{ $l['chargement'] }}</div>
    </div>
</div>
