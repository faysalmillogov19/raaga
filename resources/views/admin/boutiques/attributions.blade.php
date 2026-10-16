{{-- Attributions à faire : réservations dont la caution et l'avance sont payées ; « Attribuer » demande la date de signature du contrat. --}}
@php($a = __('admin.boutiques.attributions'))

<x-admin.layout :titre="$a['titre']" :sous-titre="trans_choice('admin.boutiques.attributions.nombre', $reservations->count(), ['n' => format_nombre($reservations->count())])">
    <div class="bo-carte !mt-0" data-attributions>
        <p class="texte-carte max-w-3xl">{{ $a['intro'] }}</p>
        @if ($reservations->isEmpty())
            <p class="mt-4 font-semibold text-encre">{{ $a['vide'] }}</p>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="bo-tableau">
                    <thead><tr>
                        @foreach (['reference', 'campagne', 'boutique', 'avance', 'reference_avance'] as $col)<th scope="col">{{ $a['colonnes'][$col] }}</th>@endforeach
                        <th scope="col"><span class="sr-only">{{ $a['colonnes']['action'] }}</span></th>
                    </tr></thead>
                    <tbody>
                        @foreach ($reservations as $reservation)
                            <tr data-reservation="{{ $reservation->reference }}">
                                <td class="font-semibold text-encre">{{ $reservation->reference }}</td>
                                <td><a href="{{ route('admin.boutiques.show', $reservation->campagne_boutiques_id) }}" class="lien">{{ $reservation->campagne?->nom }}</a></td>
                                <td>{{ $reservation->boutique?->code }}</td>
                                <td class="whitespace-nowrap">{{ $reservation->avance_payee_le?->format('d/m/Y') ?? $a['sans_avance'] }}</td>
                                <td class="font-mono">{{ $reservation->reference_avance }}</td>
                                <td>@include('admin.boutiques._reservation-actions', ['reservation' => $reservation])</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-admin.layout>
