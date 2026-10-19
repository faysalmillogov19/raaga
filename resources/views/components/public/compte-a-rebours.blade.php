@props(['cible', 'titre' => null, 'messages' => []])

{{--
    Compte à rebours en quatre cases de couleur (jours, heures, minutes, secondes), d'après le composant Alpine
    compteARebours de la page des boutiques. Valeurs calculées côté serveur (lisibles sans JavaScript), puis mises à jour
    chaque seconde. Les lecteurs d'écran lisent le titre et la date d'échéance, pas le défilement.
--}}
@php
    $cible = \Illuminate\Support\Carbon::parse($cible);
    $reste = max(0, (int) now()->diffInSeconds($cible, false));
    $valeurs = ['j' => intdiv($reste, 86400), 'h' => intdiv($reste % 86400, 3600), 'mn' => intdiv($reste % 3600, 60), 's' => $reste % 60];
    $cases = [['j', 'jours', 'bleu'], ['h', 'heures', 'vert'], ['mn', 'minutes', 'orange'], ['s', 'secondes', 'rose']];
    $libelles = __('public.nuit.compte_a_rebours');
@endphp
<div {{ $attributes->class(['nuit-rebours']) }} x-data="compteARebours(@js($cible->toIso8601String()), @js((object) $messages), true)" data-compte-a-rebours>
    @if ($titre)<p class="nuit-rebours-titre">{{ $titre }}</p>@endif
    <div class="nuit-rebours-cases" aria-hidden="true">
        @foreach ($cases as [$cle, $unite, $couleur])
            <div>
                <span class="nuit-rebours-chiffre nuit-c-{{ $couleur }}" x-text="deux({{ $cle }})">{{ str_pad((string) $valeurs[$cle], 2, '0', STR_PAD_LEFT) }}</span>
                <span class="nuit-rebours-unite">{{ $libelles[$unite] }}</span>
            </div>
        @endforeach
    </div>
    <p class="sr-only">{{ __('public.nuit.compte_a_rebours.echeance', ['date' => $cible->translatedFormat('j F Y à H\hi')]) }}</p>
</div>
