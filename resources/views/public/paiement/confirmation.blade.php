{{-- Confirmation simple après paiement réussi (le reçu complet vient à l'étape suivante). --}}
<x-public.layout :titre="__('public.paiement.confirme_titre')">
    <div class="conteneur max-w-2xl py-12 tab:py-16">
        <x-etat-vide icone="check" :titre="__('public.paiement.confirme_titre')" :lien="route('espace')" :libelle-lien="__('public.pied.mon_espace')">
            {{ __('public.paiement.confirme') }}
        </x-etat-vide>

        <dl class="carte mt-6 space-y-2 p-5 text-[0.95rem]">
            <div class="flex justify-between gap-3"><dt>{{ __('public.paiement.reference') }}</dt><dd class="whitespace-nowrap font-bold tracking-wide text-encre">{{ $souscription->reference }}</dd></div>
            <div class="flex justify-between gap-3"><dt>{{ __('public.paiement.reference_paiement') }}</dt><dd class="break-all font-semibold">{{ $paiement->reference }}</dd></div>
            <div class="flex justify-between gap-3"><dt>{{ __('public.souscription.recap_actions') }}</dt><dd class="font-semibold">{{ format_nombre($souscription->nb_actions_demandees) }}</dd></div>
            <div class="flex justify-between gap-3"><dt>{{ __('public.paiement.montant_paye') }}</dt><dd class="font-semibold">{{ format_fcfa($paiement->montant + $paiement->frais) }}</dd></div>
            <div class="flex justify-between gap-3"><dt>{{ __('public.paiement.canal') }}</dt><dd class="font-semibold">{{ __('public.paiement.canaux.'.$paiement->canal->value) }}</dd></div>
            <div class="flex justify-between gap-3"><dt>{{ __('public.paiement.date') }}</dt><dd class="font-semibold">{{ $paiement->date_confirmation->translatedFormat('j F Y \à H\hi') }}</dd></div>
            <div class="flex justify-between gap-3 border-t border-ligne pt-2"><dt>{{ __('public.paiement.statut') }}</dt><dd class="font-bold text-vert-fonce">{{ __('public.paiement.attente_allocation') }}</dd></div>
        </dl>
    </div>
</x-public.layout>
