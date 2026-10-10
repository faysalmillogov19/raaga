{{-- Tableau des appels de fonds d'une institution. Variable : $appels. --}}
@php($col = __('institutionnel.appels.colonnes'))
@if ($appels->isEmpty())
    <p class="texte-carte mt-2">{{ __('institutionnel.appels.aucun') }}</p>
@else
    <div class="mt-3 overflow-x-auto">
        <table class="bo-tableau">
            <thead><tr>
                <th scope="col">{{ $col['numero'] }}</th><th scope="col">{{ $col['libelle'] }}</th><th scope="col">{{ $col['echeance'] }}</th>
                <th scope="col" class="montant">{{ $col['montant'] }}</th><th scope="col" class="montant">{{ $col['recu'] }}</th>
                <th scope="col">{{ $col['statut'] }}</th><th scope="col">{{ $col['reference'] }}</th>
            </tr></thead>
            <tbody>
                @foreach ($appels as $appel)
                    <tr>
                        <td>{{ $appel->numero }}</td>
                        <td><a href="{{ route('institutionnel.appels.show', $appel) }}" class="font-bold text-vert-fonce underline underline-offset-2">{{ $appel->libelle }}</a></td>
                        <td class="whitespace-nowrap">{{ $appel->echeance->format('d/m/Y') }}</td>
                        <td class="montant">{{ format_fcfa($appel->montant) }}</td>
                        <td class="montant">{{ format_fcfa($appel->montant_recu) }}</td>
                        <td><x-institutionnel.statut :statut="$appel->statut" /></td>
                        <td class="font-mono">{{ $appel->reference_virement }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
