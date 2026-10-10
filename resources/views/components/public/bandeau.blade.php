{{-- Bandeau d'annonce (paramètres de la plateforme › messages de service), affiché pendant sa période. --}}
@php
    $bandeau = plateforme('messages.bandeauAnnonce');
    $maintenant = now();
    $visible = ! empty($bandeau['texte'])
        && (empty($bandeau['debut']) || \Carbon\Carbon::parse($bandeau['debut'])->lessThanOrEqualTo($maintenant))
        && (empty($bandeau['fin']) || \Carbon\Carbon::parse($bandeau['fin'])->greaterThan($maintenant));
@endphp

@if ($visible)
    <div class="bandeau-annonce bandeau-annonce-{{ $bandeau['type'] }}" role="{{ $bandeau['type'] === 'information' ? 'status' : 'alert' }}">
        <div class="conteneur flex flex-wrap items-center justify-center gap-x-3 gap-y-1 py-2.5 text-center">
            <span class="font-semibold">{{ $bandeau['texte'] }}</span>
            @if (! empty($bandeau['lien']))
                <a href="{{ $bandeau['lien'] }}" class="font-bold underline underline-offset-2">{{ __('public.bandeau.en_savoir_plus') }}</a>
            @endif
        </div>
    </div>
@endif
