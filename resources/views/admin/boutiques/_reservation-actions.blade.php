{{-- Action d'une réservation : attribution (VALIDATEUR), seulement une fois l'avance confirmée ; il renseigne la date du contrat. --}}
@php($r = __('admin.boutiques.reservations'))
<div class="flex justify-end">
    @if ($reservation->statut === \App\Enums\StatutReservationBoutique::Reservee && auth()->user()->can(\App\Enums\Droit::BoutiquesPiloter->value))
        @if ($reservation->avanceReglee())
            <x-admin.fenetre :id="'attribuer-'.$reservation->id" :titre="__('admin.boutiques.reservations.attribuer_titre', ['code' => $reservation->boutique?->code])"
                             :action="route('admin.boutiques.attribuer', [$reservation->campagne_boutiques_id, $reservation->id])" :bouton="$r['attribuer']" declencheur-taille="petit">
                <x-slot:declencheur>{{ $r['attribuer'] }}</x-slot:declencheur>
                <p class="texte-carte mb-4">{{ $r['attribuer_aide'] }}</p>
                <p class="mb-4" data-reference-avance>{{ $r['reference_avance_auto'] }} <strong class="font-mono">{{ $reservation->reference_avance }}</strong></p>
                <x-admin.champ nom="contrat_signe_le" :libelle="ucfirst($r['champs']['contrat_signe_le'])" type="date" obligatoire />
            </x-admin.fenetre>
        @else
            <x-admin.bouton variante="contour" taille="petit" disabled :title="$r['avance_attendue']">{{ $r['attribuer'] }}</x-admin.bouton>
        @endif
    @endif
</div>
