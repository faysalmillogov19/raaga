@props(['cle', 'libelle', 'icone' => 'referentiels', 'entrees', 'badges' => [], 'ouvert' => false])

{{--
    Menu dépliant de la barre latérale : un bouton (aria-expanded, aria-controls, flèche qui pivote) qui montre ou cache
    ses sous-menus, légèrement en retrait (animation de hauteur, coupée si l'utilisateur réduit les animations).
    Ouvert d'office quand la page affichée est l'un de ses sous-menus ; sinon, dernier état mémorisé pour l'utilisateur.
    Barre latérale réduite en icônes : survol ou clic → petit panneau flottant. Badge = somme des sous-menus.
    Réutilisable : MenuBackoffice regroupe les entrées marquées « sous_menu » => {cle}.
--}}
@php
    use App\Support\MenuBackoffice;

    $id = 'sous-menu-'.$cle;
    $total = collect($entrees)->sum(fn (array $e) => $badges[$e['cle']] ?? 0);
@endphp

<li class="adm-deroulant" x-data="menuDeroulant({ deplie: @js($ouvert), cle: @js($cle), url: @js(route('admin.preferences.menu')) })"
    @mouseleave="fermerBientot()" @mouseenter="garder()" @click.outside="flottant = false" @keydown.escape="flottant = false; $refs.bouton.focus()">
    <button type="button" x-ref="bouton" class="bo-lien adm-deroulant-bouton" aria-controls="{{ $id }}" aria-expanded="{{ $ouvert ? 'true' : 'false' }}"
            :aria-expanded="(deplie || flottant) ? 'true' : 'false'" title="{{ $libelle }}" @click="basculer()" @mouseenter="survol()">
        <x-icon :name="$icone" class="adm-icone-menu" />
        <span class="adm-libelle">{{ $libelle }}</span>
        @if ($total)
            <span class="adm-compteur">{{ $total }}<span class="sr-only"> {{ trans_choice('admin.a_traiter', $total, ['n' => $total]) }}</span></span>
        @endif
        <svg class="adm-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m9 6 6 6-6 6"/></svg>
    </button>
    <div id="{{ $id }}" @class(['adm-deroulant-corps', 'est-deplie' => $ouvert]) :class="{ 'est-deplie': deplie, 'est-flottant': flottant }" :style="flottant ? `top: ${haut}px` : ''">
        <ul class="adm-deroulant-liste" x-effect="$el.inert = ! (deplie || flottant)" @unless ($ouvert) inert @endunless>
            @foreach ($entrees as $entree)
                @php
                    $actif = MenuBackoffice::estActive($entree);
                    $n = $badges[$entree['cle']] ?? 0;
                @endphp
                <li>
                    <a href="{{ route($entree['route']) }}" @class(['bo-lien', 'adm-sous-lien', 'bo-lien-actif' => $actif]) @if ($actif) aria-current="page" @endif>
                        <span class="adm-libelle">{{ __('admin.menu.'.$entree['cle']) }}</span>
                        @if ($n)
                            <span class="adm-compteur">{{ $n }}<span class="sr-only"> {{ trans_choice('admin.a_traiter', $n, ['n' => $n]) }}</span></span>
                        @endif
                    </a>
                </li>
            @endforeach
        </ul>
    </div>
</li>
