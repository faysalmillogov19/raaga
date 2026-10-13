{{-- Suivi d'une candidature : statut écrit, caution, délai de paiement. --}}
@php
    use App\Enums\StatutCandidatureBoutique as S;

    $c = $candidature;
    $l = __('public.boutiques.candidature');
    $badge = ['RESERVEE' => 'bg-jaune-pale text-encre', 'PAYEE' => 'bg-vert-pale text-vert-fonce', 'RETENUE' => 'bg-vert-fonce text-white', 'NON_RETENUE' => 'bg-fond text-encre', 'EXPIREE' => 'bg-fond text-gris'];
@endphp

<x-public.layout :titre="$l['titre']">
    <x-public.page-entete :titre="$l['titre']" :fil="[['libelle' => __('public.boutiques.titre'), 'url' => route('boutiques')], ['libelle' => $l['titre']]]" />

    <div class="conteneur max-w-2xl py-8 tab:py-12">
        @if (session('candidature.creee'))
            <x-public.alerte variante="info" class="mb-6" role="status">{{ __('public.boutiques.candidature.creee', ['reference' => $c->reference]) }}</x-public.alerte>
        @endif

        <section class="carte p-5" aria-labelledby="candidature-titre">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <h2 id="candidature-titre" class="!text-lg">{{ $c->categorie->intitule() }}</h2>
                <span class="rounded-full px-3 py-1 text-sm font-bold {{ $badge[$c->statut->value] }}">{{ $c->statut->label() }}</span>
            </div>
            <dl class="mt-4 space-y-2 text-[0.95rem]">
                <div class="flex justify-between gap-3"><dt>{{ $l['reference'] }}</dt><dd class="whitespace-nowrap font-bold tracking-wide text-encre">{{ $c->reference }}</dd></div>
                <div class="flex justify-between gap-3"><dt>{{ $l['campagne'] }}</dt><dd class="text-right font-semibold">{{ $c->campagne->nom }}</dd></div>
                @if ($c->boutique)
                    <div class="flex justify-between gap-3"><dt>{{ $l['boutique'] }}</dt><dd class="text-right font-extrabold text-vert-fonce">{{ $c->boutique->numero }}@if ($c->boutique->bloc) · {{ $c->boutique->bloc }}@endif</dd></div>
                @endif
                <div class="flex justify-between gap-3 border-t border-ligne pt-2"><dt class="font-semibold text-encre">{{ $l['caution'] }}</dt><dd class="text-xl font-extrabold text-vert-fonce">{{ format_fcfa($c->caution) }}</dd></div>
            </dl>
            @if ($c->statut === S::Reservee && $c->expire_le->isFuture())
                <div class="mt-4 flex flex-col gap-2 border-t border-ligne pt-4 sm:flex-row sm:items-center sm:justify-between">
                    <p class="texte-carte">{{ __('public.boutiques.candidature.payer_avant', ['date' => $c->expire_le->format('d/m/Y \à H\hi')]) }}</p>
                    <a href="{{ route('boutiques.caution.show', $c->reference) }}" class="btn-primaire">{{ $l['payer'] }}</a>
                </div>
            @elseif (in_array($c->statut, [S::Payee, S::Retenue, S::NonRetenue], true))
                <p class="mt-4 texte-carte">{{ $c->statut === S::Payee ? $l['attente_tirage'] : ($c->statut === S::Retenue ? $l['retenue'] : $l['non_retenue']) }}</p>
                <a href="{{ route('boutiques.caution.recu', $c->reference) }}" class="btn-secondaire mt-4" download>{{ $l['recu'] }}</a>
            @endif
        </section>

        <x-public.alerte variante="attention" class="mt-6">{{ __('public.boutiques.independance') }}</x-public.alerte>
    </div>
</x-public.layout>
