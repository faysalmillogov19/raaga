{{--
    Démarche de la procédure allégée (10/11/2026) : récépissé de préinscription, demande de l'IFU et du RCCM, dépôt dans
    « Vérifier mon identité », carte. Lieu : paramètre « Formalisation : où présenter le récépissé », affiché s'il est rempli.
    Variables : $sansIntro (facultative), $premiere (première étape affichée, 1 par défaut).
--}}
@php
    $dm = __('public.formalisation.demarche');
    $lieu = plateforme('messages.lieuFormalisation');
    $premiere ??= 1;
@endphp
<div class="encadre encadre-info" data-demarche-formalisation>
    <h3>{{ $dm['titre'] }}</h3>
    <div>
        @unless ($sansIntro ?? false)
            <p>{{ $dm['intro'] }}</p>
        @endunless
        <ol class="mt-2 list-decimal space-y-1 pl-5" start="{{ $premiere }}">
            @foreach (array_slice($dm['etapes'], $premiere - 1) as $etape)
                <li>{{ str_replace(':mois', (string) \App\Services\Boutiques\Formalisation::delaiMois(), $etape) }}</li>
            @endforeach
        </ol>
        @if (filled($lieu))
            <p class="mt-2"><span class="font-semibold">{{ $dm['lieu'] }}</span> {{ $lieu }}</p>
        @endif
    </div>
</div>
