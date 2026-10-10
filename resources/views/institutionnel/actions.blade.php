@php($col = __('institutionnel.actions.colonnes'))

<x-institutionnel.layout :titre="__('institutionnel.menu.actions')" :fil="[['libelle' => __('institutionnel.menu.actions')]]">
    <div class="bo-carte !mt-0">
        @if ($lignes->isEmpty())
            <p class="texte-carte">{{ __('institutionnel.actions.aucune') }}</p>
        @else
            <p class="font-bold text-encre">{{ __('institutionnel.actions.total', ['n' => format_nombre($lignes->sum('nb_actions'))]) }}</p>
            <div class="mt-3 overflow-x-auto">
                <table class="bo-tableau">
                    <thead><tr>
                        <th scope="col">{{ $col['certificat'] }}</th><th scope="col" class="montant">{{ $col['actions'] }}</th><th scope="col">{{ $col['plage'] }}</th>
                        <th scope="col">{{ $col['date'] }}</th><th scope="col">{{ $col['convention'] }}</th><th scope="col">{{ $col['attestation'] }}</th>
                    </tr></thead>
                    <tbody>
                        @foreach ($lignes as $l)
                            <tr>
                                <td class="font-mono">{{ $l->numero_certificat }}</td>
                                <td class="montant">{{ format_nombre($l->nb_actions) }}</td>
                                <td class="whitespace-nowrap">{{ format_nombre($l->numero_debut) }} – {{ format_nombre($l->numero_fin) }}</td>
                                <td>{{ $l->date_inscription->format('d/m/Y') }}</td>
                                <td>{{ $l->convention->reference }}</td>
                                <td><a href="{{ route('institutionnel.actions.attestation', $l) }}" class="font-bold text-vert-fonce underline underline-offset-2">{{ __('institutionnel.actions.telecharger') }}</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-institutionnel.layout>
