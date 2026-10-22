@props(['titre', 'intro' => null, 'fil' => [], 'sombre' => true])

{{--
    En-tête des pages internes : fil d'Ariane, titre, introduction. $fil = [['libelle' => …, 'url' => …|null], …]
    Thème « nuit » (« sombre », vrai par défaut) : bandeau bleu nuit, titre « léger + gras » (dernier mot en vert vif ;
    en jaune sur la démarche « Investir »).
--}}
@php
    $demarche = \App\Support\Demarche::courante();
    $nuit = $sombre && \App\Support\ThemePublic::nuit();
    // Démarche « Investir » : accents jaunes en thème « nuit ».
    $investir = $nuit && ($demarche === 'investir' || request()->routeIs('invest.*'));
    [$leger, $fort] = \App\Support\TitreNuit::decouper($titre);
@endphp
<div @class([$nuit ? 'nuit-bandeau' : 'border-b border-ligne bg-fond', 'entete-demarche demarche-'.$demarche => $demarche, 'nuit-investir' => $investir])>
    <div @class(['conteneur', $nuit ? 'nuit-bandeau-corps' : 'py-6 tab:py-9'])>
        <div class="nav-fil">
        <x-navigation-historique :parent="\App\Support\NavigationHistorique::parente($fil, route('accueil'))" />
        <nav aria-label="{{ __('public.fil.libelle') }}">
            <ol class="flex flex-wrap items-center gap-1 text-sm text-gris">
                <li><a href="{{ route('accueil') }}" class="inline-flex min-h-[44px] items-center text-vert-fonce underline underline-offset-2">{{ __('public.menu.accueil') }}</a></li>
                @foreach ($fil as $element)
                    <li class="flex items-center gap-1">
                        <x-icon name="chevron" class="h-4 w-4" />
                        @if (! empty($element['url']) && ! $loop->last)
                            <a href="{{ $element['url'] }}" class="inline-flex min-h-[44px] items-center text-vert-fonce underline underline-offset-2">{{ $element['libelle'] }}</a>
                        @else
                            <span @if ($loop->last) aria-current="page" @endif>{{ $element['libelle'] }}</span>
                        @endif
                    </li>
                @endforeach
            </ol>
        </nav>
        </div>
        @if ($nuit)
            <h1 class="nuit-bandeau-titre">{{ $leger }}{{ $leger !== '' ? ' ' : '' }}<strong>{{ $fort }}</strong></h1>
            @if ($intro)<p class="nuit-bandeau-intro">{{ $intro }}</p>@endif
        @else
            <h1 class="mt-3">{{ $titre }}</h1>
            @if ($intro)
                <p class="mt-3 max-w-3xl text-lg text-gris">{{ $intro }}</p>
            @endif
        @endif
    </div>
</div>
