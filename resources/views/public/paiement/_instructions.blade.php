{{-- Instructions selon le type du moyen de paiement. Variables : $canal (MoyenPaiement), $s (objet réglé : souscription ou candidature), $sequestre. --}}
@php($c = $canal->type?->value)

<div class="mt-4 rounded-charte bg-fond p-4 text-[0.95rem]">
    @if ($c === 'MOBILE_MONEY')
        <p>{{ __('public.paiement.instructions.mobile', ['operateur' => $canal->label()]) }}</p>
        <p class="mt-2 font-semibold text-encre">{{ __('public.paiement.instructions.code_secret') }}</p>
    @elseif ($c === 'PASSERELLE')
        <p>{{ __('public.paiement.instructions.passerelle', ['operateur' => $canal->label()]) }}</p>
    @elseif ($c === 'VIREMENT')
        <p>{{ __('public.paiement.instructions.virement') }}</p>
        <dl class="mt-2 space-y-1">
            @foreach (['titulaire', 'banque', 'numero'] as $champ)
                <div class="flex flex-wrap justify-between gap-x-3"><dt>{{ __('public.paiement.sequestre.'.$champ) }}</dt>
                    <dd class="break-all font-semibold text-encre">{{ $sequestre[$champ] ?? __('public.paiement.sequestre.non_communique') }}</dd></div>
            @endforeach
            <div class="flex flex-wrap justify-between gap-x-3 border-t border-ligne pt-1"><dt class="font-semibold text-encre">{{ __('public.paiement.reference_virement') }}</dt>
                <dd class="font-extrabold tracking-wide text-vert-fonce">{{ $s->reference }}</dd></div>
        </dl>
        <p class="mt-2 font-semibold text-encre">{{ __('public.paiement.instructions.reference_obligatoire') }}</p>
    @elseif ($c === 'ESPECES')
        <p>{{ __('public.paiement.instructions.especes') }}</p>
        <p class="mt-2 text-center text-2xl font-extrabold tracking-widest text-vert-fonce">{{ $s->reference }}</p>
        <a href="{{ route('guichets') }}" class="lien-fort mt-1">{{ __('public.guichets.titre') }}</a>
    @endif
</div>
