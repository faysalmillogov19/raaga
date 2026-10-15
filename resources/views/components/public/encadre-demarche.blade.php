{{--
    Deux démarches distinctes : rappel visible sur chaque page des actions (et à chaque étape de la souscription et du
    paiement) et sur chaque page des boutiques (candidature, caution, suivi), avec le badge et le code couleur du parcours.
--}}
@php
    $demarche = \App\Support\Demarche::courante();
    $texte = ['investir' => 'actions', 'boutiques' => 'boutiques'][$demarche] ?? null;
@endphp

@if ($demarche)
    <div class="encadre-demarche demarche-{{ $demarche }}" role="note" data-demarche="{{ $texte }}">
        <p class="conteneur flex flex-wrap items-center gap-x-3 gap-y-2 py-3 font-semibold">
            <x-public.badge-demarche :demarche="$demarche" />
            <span>{{ __('public.demarches.'.$texte) }}</span>
        </p>
    </div>
@endif
