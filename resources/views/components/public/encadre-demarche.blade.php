{{--
    Deux démarches distinctes : rappel visible sur chaque page des actions (et à chaque étape de la souscription et du
    paiement) et sur chaque page des boutiques (candidature, caution, suivi).
--}}
@php
    $demarche = match (true) {
        request()->routeIs('offre', 'transparence', 'actualites.*', 'souscrire', 'souscrire.*', 'souscription.*', 'paiement', 'paiement.*', 'espace.documents.*') => 'actions',
        request()->routeIs('boutiques', 'boutiques.*') => 'boutiques',
        default => null,
    };
@endphp

@if ($demarche)
    <div class="encadre-demarche" role="note" data-demarche="{{ $demarche }}">
        <p class="conteneur flex items-start gap-2 py-3 font-semibold">
            <x-icon name="info" class="mt-0.5 h-5 w-5 shrink-0" /> <span>{{ __('public.demarches.'.$demarche) }}</span>
        </p>
    </div>
@endif
