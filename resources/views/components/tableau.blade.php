@props(['t', 'libelle' => null])

{{--
    Tableau de données traité côté serveur (App\Support\Tableau\Tableau), dans une carte : filtres en haut
    (listes + « Réinitialiser »), « Afficher N lignes » à gauche et « Rechercher » à droite (300 ms), en-têtes triables,
    lignes zébrées, pagination, export du résultat filtré. L'état tient dans l'adresse. Avec JavaScript, seul le bloc
    des résultats est rechargé ; sans JavaScript, le formulaire et les liens fonctionnent normalement.
    Sous 768 px, le tableau défile horizontalement dans la carte.
--}}
@php($l = __('admin.donnees'))
{{-- Soumission groupée : barre d'action et fenêtre de confirmation autour du tableau (voir tableau-soumission). --}}
@if ($t->selection)
<div x-data="soumissionGroupee(@js(['controle' => route('admin.soumission.controle')]))" @tableau-charge="compter()" data-soumission="{{ $t->selection }}">
@endif
<div {{ $attributes->merge(['class' => 'bo-donnees']) }} x-data="tableau" data-tableau="{{ $t->id }}" data-lignes-defaut="{{ $t->lignesDefaut }}"
     :aria-busy="charge ? 'true' : 'false'">
    <form method="GET" action="{{ request()->url() }}" x-ref="formulaire" @submit.prevent="soumettre()" class="bo-donnees-outils" role="search"
          aria-label="{{ $libelle ? $l['rechercher'].' : '.$libelle : $l['rechercher'] }}">
        <input type="hidden" name="tri" value="{{ $t->parametres()['tri'] ?? '' }}">

        @foreach ($t->filtres as $f)
            @if ($f->masque && isset($t->etat['filtres'][$f->cle]))
                <input type="hidden" name="{{ $f->cle }}" value="{{ $t->etat['filtres'][$f->cle] }}">
            @endif
        @endforeach
        @if (collect($t->filtres)->contains(fn ($f) => ! $f->masque))
            <div class="bo-donnees-filtres">
                @foreach (collect($t->filtres)->reject(fn ($f) => $f->masque) as $f)
                    <div class="formulaire-champ adm-champ !mt-0">
                        <label for="{{ $t->id }}-{{ $f->cle }}" class="adm-libelle-petit">{{ $f->libelle }}</label>
                        <select id="{{ $t->id }}-{{ $f->cle }}" name="{{ $f->cle }}" class="champ adm-champ-petit" @change="soumettre()">
                            <option value="">{{ $l['tous'] }}</option>
                            @foreach ($f->options as $valeur => $texte)
                                <option value="{{ $valeur }}" @selected(($t->etat['filtres'][$f->cle] ?? null) === (string) $valeur)>{{ $texte }}</option>
                            @endforeach
                        </select>
                    </div>
                @endforeach
                <div class="bo-donnees-reinitialiser">
                    <x-admin.bouton variante="contour" :href="$t->urlInitiale()" x-on:click.prevent="reinitialiser($event)">{{ $l['reinitialiser'] }}</x-admin.bouton>
                </div>
            </div>
        @endif

        <div class="bo-donnees-ligne">
            <label class="bo-donnees-lignes">
                <span>{{ $l['afficher'] }}</span>
                <select name="lignes" class="champ adm-champ-petit !w-auto" @change="soumettre()">
                    @foreach (\App\Support\Tableau\Tableau::LIGNES as $n)
                        <option value="{{ $n }}" @selected($t->etat['lignes'] === $n)>{{ $n }}</option>
                    @endforeach
                </select>
                <span>{{ $l['lignes'] }}</span>
            </label>
            @if ($t->aDesRecherchables())
                <label class="bo-donnees-recherche">
                    <span>{{ $l['rechercher'] }}</span>
                    <input type="search" name="q" value="{{ $t->etat['q'] }}" class="champ adm-champ-petit" autocomplete="off" maxlength="100"
                           @input.debounce.300ms="soumettre()">
                </label>
            @endif
        </div>

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

@if ($t->selection)
    @include('components.tableau-soumission')
</div>
@endif
