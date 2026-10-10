@php
    $c = __('institutionnel.bo.conventions');
    $pct = $objectif > 0 ? format_nombre($engage * 100 / $objectif, 1)."\u{00A0}%" : '—';
@endphp

<x-admin.layout :titre="$c['titre']" :fil="[['libelle' => $c['titre']]]">
    <div class="bo-carte !mt-0">
        <p class="font-bold text-encre">{{ __('institutionnel.bo.conventions.indicateur', ['engage' => format_md_fcfa($engage), 'objectif' => format_md_fcfa($objectif), 'pct' => $pct]) }}</p>
        <div class="financement-barre mt-2" aria-hidden="true"><span class="remplissage-gris" style="width: {{ $objectif > 0 ? min(100, round($engage * 100 / $objectif, 1)) : 0 }}%"></span></div>
    </div>

    <div class="bo-carte">
        @can(\App\Enums\Droit::ConventionsSaisir->value)
            <div class="mb-4 flex justify-end"><a href="{{ route('admin.conventions.create') }}" class="bo-bouton bo-bouton-principal">{{ $c['nouvelle'] }}</a></div>
        @endcan
        <div class="overflow-x-auto">
            <table class="bo-tableau">
                <thead><tr>@foreach ($c['colonnes'] as $cle => $libelle)<th scope="col" @class(['montant' => in_array($cle, ['actions', 'engage'], true)])>{{ $libelle }}</th>@endforeach</tr></thead>
                <tbody>
                    @forelse ($conventions as $convention)
                        <tr>
                            <td><a href="{{ route('admin.conventions.show', $convention) }}" class="font-bold text-vert-fonce underline underline-offset-2">{{ $convention->reference }}</a></td>
                            <td>{{ $convention->institution->nomCourt() }}</td>
                            <td>@include('admin.parametrage._statut', ['statut' => $convention->statut])</td>
                            <td class="montant">{{ format_nombre($convention->nb_actions) }}</td>
                            <td class="montant">{{ format_fcfa($convention->montant_engage) }}</td>
                            <td>{{ $convention->estSignee() ? $convention->signee_le->format('d/m/Y') : ($convention->signatureEnAttente() ? __('institutionnel.bo.conventions.depot', ['date' => $convention->signature_deposee_le->format('d/m/Y'), 'nom' => '']) : '—') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6">{{ $c['aucune'] }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $conventions->links() }}</div>
    </div>
</x-admin.layout>
