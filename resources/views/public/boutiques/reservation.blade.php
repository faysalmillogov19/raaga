{{-- Suivi d'une réservation de boutique (titulaire uniquement) : statut, montants, reçu de caution. --}}
@php
    use App\Enums\StatutReservationBoutique;

    $r = __('public.boutiques.reservation');
    $c = $reservation;
    $message = match ($c->statut) {
        StatutReservationBoutique::Reservee => $r['reservee'],
        StatutReservationBoutique::Attribuee => $r['attribuee'],
        StatutReservationBoutique::Expiree => $r['expiree'],
        StatutReservationBoutique::Annulee => $r['annulee'],
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
                <div class="flex justify-between gap-3"><dt>{{ $r['statut'] }}</dt><dd class="font-semibold">{{ $c->statut->label() }}</dd></div>
                @foreach ($c->lignesMontants() as $ligne)
                    <div class="flex justify-between gap-3"><dt>{{ $ligne['libelle'] }}</dt><dd class="font-semibold">{{ format_fcfa($ligne['montant']) }}</dd></div>
                @endforeach
            </dl>
            @if ($message)<p class="mt-4 font-semibold text-encre">{{ $message }}</p>@endif
            @if ($c->attendPaiement())
                <p class="mt-4 texte-carte">{{ __('public.boutiques.reservation.payer_avant', ['date' => $c->expire_le->translatedFormat('j F Y à H\hi')]) }}</p>
                <a href="{{ route('boutiques.reservation.show', $c->reference) }}" class="btn-primaire mt-3">{{ $r['payer'] }}</a>
            @elseif (in_array($c->statut, [StatutReservationBoutique::Reservee, StatutReservationBoutique::Attribuee], true))
                {{-- Réservée : date limite de paiement de l'avance (figée au passage en « réservée »). --}}
                @if ($c->statut === StatutReservationBoutique::Reservee && $c->avance_due_le)
                    <p class="mt-4 font-semibold text-encre" data-avance-due>{{ __($c->avance_due_le->isPast() ? 'public.boutiques.reservation.avance_depassee' : 'public.boutiques.reservation.avance_avant', ['date' => $c->avance_due_le->translatedFormat('j F Y à H\hi')]) }}</p>
                @endif
                <a href="{{ route('boutiques.reservation.recu', $c->reference) }}" class="btn-secondaire mt-4">{{ $r['recu'] }}</a>
            @endif
        </section>
    </div>
</x-public.layout>
