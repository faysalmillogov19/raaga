<x-admin.layout :titre="__('admin.boutiques.titre')" :fil="[['libelle' => __('admin.boutiques.titre')]]">
    <div class="bo-carte">
        <div class="mb-4 flex flex-wrap items-start justify-between gap-4">
            <p class="texte-carte max-w-3xl">{{ __('admin.boutiques.intro') }}</p>
            <div class="flex flex-wrap gap-3">
                <x-admin.bouton variante="contour" href="{{ route('admin.boutiques.grilles') }}">{{ __('admin.grilles.titre') }}</x-admin.bouton>
                @can(\App\Enums\Droit::BoutiquesSaisir->value)
                    <x-admin.bouton variante="principal" href="{{ route('admin.boutiques.create') }}">{{ __('admin.boutiques.nouvelle') }}</x-admin.bouton>
                @endcan
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="bo-tableau">
                <thead>
                    <tr>
                        @foreach (__('admin.boutiques.colonnes') as $cle => $libelle)
                            @continue($cle === 'candidatures')
                            <th scope="col" @class(['montant' => $cle === 'boutiques'])>{{ $libelle }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($campagnes as $c)
                        <tr>
                            <td><a href="{{ route('admin.boutiques.show', $c) }}" class="font-bold text-vert-fonce underline underline-offset-2">{{ $c->nom }}</a></td>
                            <td><x-admin.badge-statut :statut="$c->statut" /></td>
                            <td class="whitespace-nowrap">{{ $c->date_ouverture?->format('d/m/Y') }} → {{ $c->date_cloture?->format('d/m/Y') }}</td>
                            <td class="montant">{{ format_nombre((int) $c->categories_sum_nombre_boutiques) }}</td>
                            <td>{{ $c->version ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5">{{ __('admin.boutiques.aucune') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $campagnes->links('admin._pagination') }}</div>
    </div>
</x-admin.layout>
