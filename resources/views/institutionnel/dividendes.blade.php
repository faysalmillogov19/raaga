@php($d = __('institutionnel.dividendes'))

<x-institutionnel.layout :titre="__('institutionnel.menu.dividendes')" :fil="[['libelle' => __('institutionnel.menu.dividendes')]]">
    <div class="bo-carte !mt-0">
        @if ($distributions->isEmpty())
            <p class="texte-carte">{{ __('institutionnel.a_venir_dividendes') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="bo-tableau">
                    <thead><tr>
                        @foreach ($d['colonnes'] as $cle => $libelle)<th scope="col" @class(['montant' => in_array($cle, ['actions', 'brut', 'retenue', 'net'], true)])>{{ $libelle }}</th>@endforeach
                    </tr></thead>
                    <tbody>
                        @foreach ($distributions as ['distribution' => $distribution, 'ligne' => $ligne])
                            <tr>
                                <td class="font-semibold">{{ $distribution->exercice }}</td>
                                <td class="montant">{{ format_nombre($ligne->nbActions) }}</td>
                                <td class="montant">{{ format_fcfa($ligne->brut) }}</td>
                                <td class="montant">{{ format_fcfa($ligne->retenue) }}</td>
                                <td class="montant font-bold">{{ format_fcfa($ligne->net) }}</td>
                                <td class="whitespace-nowrap">{{ $distribution->date_mise_en_paiement->format('d/m/Y') }}</td>
                                <td>{{ $distribution->statut->label() }}</td>
                                <td><a href="{{ route('institutionnel.dividendes.avis', $distribution) }}" class="font-bold text-vert-fonce underline underline-offset-2" target="_blank">{{ $d['avis'] }}</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="texte-carte mt-3">{{ $d['virement'] }}</p>
        @endif
    </div>
</x-institutionnel.layout>
