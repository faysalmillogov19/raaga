<x-admin.layout :titre="__('admin.dividendes.titre')" :fil="[['libelle' => __('admin.dividendes.titre')]]">
    <div class="bo-carte">
        @can(\App\Enums\Droit::DividendesSaisir->value)
            <div class="mb-4 flex justify-end">
                <a href="{{ route('admin.dividendes.create') }}" class="bo-bouton bo-bouton-principal">{{ __('admin.dividendes.nouvelle') }}</a>
            </div>
        @endcan

        <div class="overflow-x-auto">
            <table class="bo-tableau">
                <thead><tr>@foreach (__('admin.dividendes.colonnes') as $cle => $libelle)<th scope="col" @class(['montant' => $cle === 'montant'])>{{ $libelle }}</th>@endforeach</tr></thead>
                <tbody>
                    @forelse ($distributions as $d)
                        <tr>
                            <td><a href="{{ route('admin.dividendes.show', $d) }}" class="font-bold text-vert-fonce underline underline-offset-2">{{ $d->exercice }}</a></td>
                            <td>{{ $d->classe_actions->value }}</td>
                            <td>@include('admin.parametrage._statut', ['statut' => $d->statut])</td>
                            <td>{{ $d->mode_calcul->label() }}</td>
                            <td class="montant">{{ $d->montant_global ? format_fcfa($d->montant_global) : format_fcfa((int) $d->dividende_brut_par_action).' / action' }}</td>
                            <td>{{ $d->date_arrete?->format('d/m/Y') }}</td>
                            <td>{{ $d->date_mise_en_paiement?->format('d/m/Y') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7">{{ __('admin.dividendes.aucune') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $distributions->links() }}</div>
    </div>
</x-admin.layout>
