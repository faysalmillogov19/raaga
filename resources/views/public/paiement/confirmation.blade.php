{{-- Confirmation après paiement réussi : pastille de succès, récapitulatif, reçu PDF, accès à Mon espace. --}}
@php($s = $souscription)

<x-public.layout :titre="__('public.recu.titre_page')">
    <div class="conteneur max-w-2xl py-10 tab:py-16">
        <div class="text-center">
            <span class="mx-auto flex h-20 w-20 items-center justify-center rounded-full bg-vert text-white" aria-hidden="true">
                <x-icon name="check" class="h-10 w-10" />
            </span>
            <h1 class="mt-5">{{ __('public.recu.enregistree') }}</h1>
            <p class="mt-2 text-lg">{{ __('public.recu.intro') }}</p>
        </div>

        <section class="carte mt-8 p-5" aria-labelledby="recap-titre">
            <h2 id="recap-titre" class="!text-lg">{{ __('public.souscription.recap') }}</h2>
            <dl class="mt-3 space-y-2 text-[0.95rem]">
                <div class="flex justify-between gap-3"><dt>{{ __('public.recu.numero') }}</dt><dd class="whitespace-nowrap font-bold tracking-wide text-encre">{{ $numeroRecu }}</dd></div>
                <div class="flex justify-between gap-3"><dt>{{ __('public.paiement.date') }}</dt><dd class="font-semibold">{{ $paiement->date_confirmation->format('d/m/Y') }}</dd></div>
                <div class="flex justify-between gap-3"><dt>{{ __('public.souscription.recap_actions') }}</dt><dd class="font-semibold">{{ format_nombre($s->nb_actions_demandees) }}</dd></div>
                <div class="flex justify-between gap-3"><dt>{{ __('public.paiement.montant_paye') }}</dt><dd class="font-semibold">{{ format_fcfa($paiement->montant + $paiement->frais) }}</dd></div>
                <div class="flex justify-between gap-3"><dt>{{ __('public.paiement.canal') }}</dt><dd class="font-semibold">{{ __('public.paiement.canaux.'.$paiement->canal->value) }}</dd></div>
                <div class="flex justify-between gap-3"><dt>{{ __('public.paiement.reference_paiement') }}</dt><dd class="break-all text-right font-semibold">{{ $paiement->reference }}</dd></div>
                <div class="flex justify-between gap-3"><dt>{{ __('public.paiement.reference') }}</dt><dd class="whitespace-nowrap font-semibold">{{ $s->reference }}</dd></div>
                <div class="flex justify-between gap-3 border-t border-ligne pt-2"><dt>{{ __('public.paiement.statut') }}</dt>
                    <dd class="font-bold text-vert-fonce">{{ $s->statut === \App\Enums\StatutSouscription::Allouee ? __('public.recu.allouee') : __('public.paiement.attente_allocation') }}</dd></div>
            </dl>
        </section>

        <div class="mt-6 flex flex-col gap-3 sm:flex-row">
            <a href="{{ route('paiement.recu', $s->reference) }}" class="btn-primaire flex-1" download>{{ __('public.recu.telecharger') }}</a>
            <a href="{{ route('espace') }}" class="btn-secondaire flex-1">{{ __('public.recu.espace') }}</a>
        </div>

        <p class="mt-6 texte-carte">{{ __('public.recu.sms_envoye') }}</p>
    </div>
</x-public.layout>
