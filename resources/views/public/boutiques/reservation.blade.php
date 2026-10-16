{{-- Suivi d'une réservation de boutique (titulaire uniquement) : statut, montants, reçu de caution. --}}
@php
    use App\Enums\StatutReservationBoutique;

    $r = __('public.boutiques.reservation');
    $c = $reservation;
    $message = match ($c->statut) {
        StatutReservationBoutique::Reservee => $r['reservee'],
        StatutReservationBoutique::Attribuee => $r['attribuee'],
        StatutReservationBoutique::Expiree => $r['expiree'],
        StatutReservationBoutique::Annulee => $c->annulation_motif === \App\Models\ReservationBoutique::ANNULATION_AVANCE ? $r['annulee_avance'] : $r['annulee'],
        default => null,
    };
@endphp

<x-public.layout :titre="$r['suivi_titre']">
    <x-public.page-entete :titre="$r['suivi_titre']" :fil="[['libelle' => __('public.boutiques.titre'), 'url' => route('boutiques')], ['libelle' => $c->reference]]" />

    <div class="conteneur max-w-3xl py-8 tab:py-12">
        <section class="carte p-5" data-reservation>
            <dl class="grid gap-x-6 gap-y-2 text-[0.95rem] tab:grid-cols-2">
                <div class="flex justify-between gap-3"><dt>{{ $r['reference'] }}</dt><dd class="font-bold tracking-wide text-encre">{{ $c->reference }}</dd></div>
                <div class="flex justify-between gap-3"><dt>{{ $r['boutique'] }}</dt><dd class="font-bold text-vert-fonce">{{ $c->boutique?->code }}</dd></div>
                <div class="flex justify-between gap-3"><dt>{{ $r['campagne'] }}</dt><dd class="text-right font-semibold">{{ $c->campagne->nom }}</dd></div>
                <div class="flex justify-between gap-3"><dt>{{ $r['statut'] }}</dt><dd class="font-semibold">{{ $c->libelleStatut() }}</dd></div>
                @foreach ($c->lignesMontants() as $ligne)
                    <div class="flex justify-between gap-3"><dt>{{ $ligne['libelle'] }}</dt><dd class="font-semibold">{{ format_fcfa($ligne['montant']) }}</dd></div>
                @endforeach
            </dl>
            @if ($message)<p class="mt-4 font-semibold text-encre">{{ $message }}</p>@endif
            @if ($c->naturePaiementAttendue() === \App\Models\Paiement::CAUTION)
                <p class="mt-4 texte-carte">{{ __('public.boutiques.reservation.payer_avant', ['date' => $c->expire_le->translatedFormat('j F Y à H\hi')]) }}</p>
                <a href="{{ route('boutiques.reservation.show', $c->reference) }}" class="btn-primaire mt-3">{{ $r['payer'] }}</a>
            @elseif (in_array($c->statut, [StatutReservationBoutique::Reservee, StatutReservationBoutique::Attribuee], true))
                {{-- Avance : à payer avant sa date limite (figée au passage en « réservée ») ; sinon, annulation automatique. --}}
                @if ($c->naturePaiementAttendue() === \App\Models\Paiement::AVANCE)
                    @php($date = $c->avance_due_le?->translatedFormat('j F Y à H\hi'))
                    {{-- Date limite passée : seule une preuve en contrôle retient l'annulation automatique. --}}
                    @if ($c->avance_due_le?->isPast())
                        <x-public.alerte variante="info" class="mt-4" data-avance-controle>{{ __('public.boutiques.reservation.avance_controle', ['date' => $date]) }}</x-public.alerte>
                    @elseif ($c->avance_due_le)
                        <p class="mt-4 font-semibold text-encre" data-avance-due>{{ __('public.boutiques.reservation.avance_avant', ['date' => $date]) }}</p>
                    @endif
                    <a href="{{ route('boutiques.avance.show', $c->reference) }}" class="btn-primaire mt-3" data-payer-avance>{{ $r['payer_avance'] }}</a>
                @elseif ($c->avance_payee_le)
                    <p class="mt-4 font-semibold text-encre" data-avance-payee>{{ __('public.boutiques.reservation.avance_payee', ['date' => $c->avance_payee_le->translatedFormat('j F Y')]) }}</p>
                @endif
                <div class="mt-4 flex flex-wrap gap-3">
                    <a href="{{ route('boutiques.reservation.recu', $c->reference) }}" class="btn-secondaire">{{ $r['recu'] }}</a>
                    @if ($c->avance_payee_le && $c->reference_avance)
                        <a href="{{ route('boutiques.avance.recu', $c->reference) }}" class="btn-secondaire">{{ $r['recu_avance'] }}</a>
                    @endif
                </div>
            @endif
        </section>
    </div>
</x-public.layout>
