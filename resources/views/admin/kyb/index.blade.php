@php($k = __('institutionnel.bo.kyb'))

<x-admin.layout :titre="$k['titre']" :fil="[['libelle' => $k['titre']]]">
    <div class="bo-carte !mt-0">
        <p class="texte-carte max-w-3xl">{{ $k['intro'] }}</p>
        <div class="mt-4 overflow-x-auto">
            <table class="bo-tableau">
                <tbody>
                    @forelse ($dossiers as $d)
                        <tr>
                            <td><a href="{{ route('admin.kyb.show', $d['institution']) }}" class="font-bold text-vert-fonce underline underline-offset-2">{{ $d['institution']->denomination }}</a></td>
                            <td><x-institutionnel.statut :statut="$d['institution']->statut" /></td>
                            <td>{{ __('institutionnel.bo.institutions.pieces', ['valides' => $d['validees'], 'total' => count(\App\Enums\TypeDocumentKyb::cases())]) }}</td>
                            <td>@if ($d['a_verifier'])<x-admin.badge-statut :statut="__('institutionnel.bo.kyb.a_verifier', ['n' => $d['a_verifier']])" variante="avertissement" />@endif</td>
                        </tr>
                    @empty
                        <tr><td>{{ $k['aucun'] }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-admin.layout>
