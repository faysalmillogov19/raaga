{{-- Action d'une réservation : attribution (VALIDATEUR) une fois l'avance payée et le contrat signé. --}}
@php($r = __('admin.boutiques.reservations'))
<div class="flex justify-end">
    @if ($reservation->statut === \App\Enums\StatutReservationBoutique::Reservee && auth()->user()->can(\App\Enums\Droit::BoutiquesPiloter->value))
        <x-admin.fenetre :id="'attribuer-'.$reservation->id" :titre="__('admin.boutiques.reservations.attribuer_titre', ['code' => $reservation->boutique?->code])"
                         :action="route('admin.boutiques.attribuer', [$reservation->campagne_boutiques_id, $reservation->id])" :bouton="$r['attribuer']" declencheur-taille="petit">
            <x-slot:declencheur>{{ $r['attribuer'] }}</x-slot:declencheur>
            <p class="texte-carte mb-4">{{ $r['attribuer_aide'] }}</p>
            <x-admin.champ nom="reference_avance" :libelle="ucfirst($r['champs']['reference_avance'])" maxlength="100" obligatoire />
            <x-admin.champ nom="contrat_signe_le" :libelle="ucfirst($r['champs']['contrat_signe_le'])" type="date" obligatoire class="mt-4" />
        </x-admin.fenetre>
    @endif
</div>
