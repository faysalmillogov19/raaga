<x-admin.layout :titre="__('admin.tranches.titre')" :fil="[['libelle' => __('admin.tranches.titre')]]">
    <div class="bo-carte">
        @can(\App\Enums\Droit::TranchesSaisir->value)
            <div class="mb-4 flex justify-end">
                <a href="{{ route('admin.tranches.create') }}" class="bo-bouton bo-bouton-principal">{{ __('admin.tranches.nouvelle') }}</a>
            </div>
        @endcan

        <div class="overflow-x-auto">
            <table class="bo-tableau">
                <thead>
                    <tr>
                        @foreach (__('admin.tranches.colonnes') as $cle => $libelle)
                            <th scope="col" @class(['montant' => in_array($cle, ['objectif', 'souscriptions'], true)])>{{ $libelle }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($tranches as $t)
                        <tr>
                            <td><a href="{{ route('admin.tranches.show', $t) }}" class="font-bold text-vert-fonce underline underline-offset-2">{{ $t->nom }}</a></td>
                            <td>@include('admin.parametrage._statut', ['statut' => $t->statut])</td>
                            <td class="montant">{{ format_fcfa($t->objectif_montant) }}</td>
                            <td class="whitespace-nowrap">{{ $t->date_ouverture?->format('d/m/Y') }} → {{ $t->date_cloture?->format('d/m/Y') }}</td>
                            <td>{{ $t->version ?: '—' }}</td>
                            <td class="montant">{{ format_nombre($t->souscriptions_count) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6">{{ __('admin.tranches.aucune') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $tranches->links() }}</div>
    </div>
</x-admin.layout>
