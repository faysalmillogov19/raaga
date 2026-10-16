{{--
    Paiement manuel (virement, versement, espèces) : aucun opérateur. Montant exact, compte de destination selon l'usage
    (location ou investissement), référence de paiement unique, délai restant, puis preuve à joindre (ou état « en attente
    de contrôle » une fois la preuve déposée : délai gelé). Variables : $paiement, $s, $compte, $sequestre, $routes, $etat.
--}}
@php
    $m = __('public.paiement.manuel');
    // Paiement incomplet : le montant à payer est ce qui reste, pas le total.
    $incomplet = $paiement->controle === \App\Models\Paiement::INCOMPLET;
    $aPayer = $paiement->montant + $paiement->frais - ($incomplet ? (int) $paiement->controle_montant_recu : 0);
@endphp

<section class="carte p-5" aria-labelledby="manuel-titre" data-paiement-manuel>
    <h2 id="manuel-titre" class="!text-lg">{{ __('public.paiement.manuel.titre', ['moyen' => $paiement->canal->label()]) }}</h2>

    @if ($etat === 'controle')
        <x-public.alerte variante="info" class="mt-3" role="status" data-en-controle>
            {{ __('public.paiement.manuel.en_controle', ['date' => $paiement->preuve_deposee_le->translatedFormat('j F Y à H\hi')]) }}
        </x-public.alerte>
    @elseif ($paiement->controle === \App\Models\Paiement::INCOMPLET)
        <x-public.alerte variante="attention" class="mt-3" role="alert" data-incomplet>
            {{ __('public.paiement.manuel.incomplet', ['motif' => $paiement->controle_motif, 'recu' => format_fcfa((int) $paiement->controle_montant_recu),
                'reste' => format_fcfa(max(0, $paiement->montant + $paiement->frais - (int) $paiement->controle_montant_recu)),
                'echeance' => $s->expire_le->translatedFormat('j F Y à H\hi')]) }}
        </x-public.alerte>
    @endif

    <dl class="mt-4 space-y-2 rounded-charte bg-fond p-4 text-[0.95rem]">
        <div class="flex flex-wrap justify-between gap-x-3"><dt>{{ $incomplet ? $m['reste'] : $m['montant'] }}</dt>
            <dd class="text-xl font-extrabold text-vert-fonce" data-montant-exact>{{ format_fcfa(max(0, $aPayer)) }}</dd></div>
        <div class="flex flex-wrap justify-between gap-x-3"><dt>{{ $m['compte'] }}</dt>
            <dd class="font-semibold text-encre" data-compte="{{ $compte->value }}">{{ $compte->label() }}</dd></div>
        @foreach (['titulaire', 'banque', 'numero'] as $champ)
            <div class="flex flex-wrap justify-between gap-x-3"><dt>{{ __('public.paiement.sequestre.'.$champ) }}</dt>
                <dd class="break-all font-semibold text-encre">{{ $sequestre[$champ] ?? __('public.paiement.sequestre.non_communique') }}</dd></div>
        @endforeach
        <div class="flex flex-wrap items-center justify-between gap-x-3 border-t border-ligne pt-2"><dt class="font-semibold text-encre">{{ $m['reference'] }}</dt>
            <dd class="text-2xl font-extrabold tracking-widest text-vert-fonce" data-reference-versement>{{ $paiement->reference_versement }}</dd></div>
        @if ($etat !== 'controle')
            <div class="flex flex-wrap justify-between gap-x-3"><dt>{{ $m['delai'] }}</dt>
                <dd class="font-semibold text-encre" data-delai>{{ $s->expire_le->isFuture() ? $s->expire_le->diffForHumans(['parts' => 2, 'syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE]) : $m['delai_depasse'] }}</dd></div>
        @endif
    </dl>
    <p class="mt-2 font-semibold text-encre">{{ $m['reference_obligatoire'] }}</p>
    @if ($paiement->canal->famille === \App\Enums\FamilleMoyenPaiement::Guichet)
        <a href="{{ route('guichets') }}" class="lien-fort mt-1">{{ __('public.guichets.titre') }}</a>
    @endif
    @if ($paiement->canal->instructions)
        <p class="mt-3 whitespace-pre-line" data-instructions-moyen>{{ $paiement->canal->instructions }}</p>
    @endif

    @if ($etat !== 'controle')
        <form method="POST" action="{{ $routes['preuve'] }}" enctype="multipart/form-data" class="mt-5 border-t border-ligne pt-4" novalidate>
            @csrf
            <label for="preuve" class="block font-semibold text-encre">{{ $m['preuve'] }}</label>
            <p id="preuve-aide" class="texte-carte">{{ $m['preuve_aide'] }}</p>
            <input id="preuve" name="preuve" type="file" accept="image/jpeg,image/png,application/pdf" required aria-describedby="preuve-aide preuve-erreur"
                   @error('preuve') aria-invalid="true" @enderror
                   class="mt-2 block w-full text-base file:mr-3 file:min-h-[44px] file:rounded-charte file:border-2 file:border-vert-fonce file:bg-white file:px-4 file:font-semibold file:text-vert-fonce">
            <x-public.erreur :messages="$errors->get('preuve')" id="preuve-erreur" />
            <button type="submit" class="btn-primaire mt-4 w-full sm:w-auto">{{ $m['envoyer'] }}</button>
        </form>
        <form method="POST" action="{{ $routes['changer'] }}" class="mt-3">
            @csrf
            <button type="submit" class="btn-secondaire w-full sm:w-auto">{{ __('public.paiement.changer') }}</button>
        </form>
    @endif
</section>
