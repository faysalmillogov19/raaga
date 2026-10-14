@php($i = __('institutionnel.bo.institutions'))

<x-admin.layout :titre="$i['titre']" :fil="[['libelle' => $i['titre']]]">
    <div class="bo-carte !mt-0">
        <div class="mb-4 flex justify-end">
            <x-admin.bouton variante="principal" href="{{ route('admin.institutions.create') }}">{{ $i['nouvelle'] }}</x-admin.bouton>
        </div>
        <div class="overflow-x-auto">
            <table class="bo-tableau">
                <thead><tr>@foreach ($i['colonnes'] as $libelle)<th scope="col">{{ $libelle }}</th>@endforeach</tr></thead>
                <tbody>
                    @forelse ($institutions as $institution)
                        @php($convention = $institution->conventions->first())
                        <tr>
                            <td><a href="{{ route('admin.institutions.show', $institution) }}" class="font-bold text-vert-fonce underline underline-offset-2">{{ $institution->denomination }}</a>
                                @if ($institution->sigle)<span class="texte-carte"> ({{ $institution->sigle }})</span>@endif</td>
                            <td><x-institutionnel.statut :statut="$institution->statut" /></td>
                            <td>{{ __('institutionnel.bo.institutions.pieces', ['valides' => \App\Http\Controllers\Admin\InstitutionsController::piecesValidees($institution), 'total' => $totalPieces]) }}</td>
                            <td>{{ $institution->utilisateurs_count }}</td>
                            <td>{{ $convention ? $convention->reference.' · '.$convention->statut->label().($convention->estSignee() ? ' · signée' : '') : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5">{{ $i['aucune'] }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $institutions->links('admin._pagination') }}</div>
    </div>
</x-admin.layout>
