@php
    use App\Enums\Droit;
    use App\Enums\StatutDocumentKyc;
    use App\Enums\TypeDocumentKyb;

    $k = __('institutionnel.bo.kyb');
@endphp

<x-admin.layout :titre="$institution->denomination" :fil="[['libelle' => $k['titre'], 'url' => route('admin.kyb')], ['libelle' => $institution->nomCourt()]]">
    <div class="bo-carte !mt-0">
        <p>{{ __('institutionnel.organisation.statut') }} : <x-institutionnel.statut :statut="$institution->statut" /></p>
        <div class="mt-4 overflow-x-auto">
            <table class="bo-tableau">
                <tbody>
                    @foreach (TypeDocumentKyb::cases() as $type)
                        @php($piece = $pieces->get($type->value))
                        <tr>
                            <td class="font-semibold">{{ $type->label() }}</td>
                            <td>
                                @if ($piece)
                                    <x-institutionnel.statut :statut="$piece->statut" />
                                    <span class="texte-carte block">{{ __('institutionnel.bo.kyb.deposee_le', ['date' => $piece->created_at->format('d/m/Y'), 'nom' => $piece->deposant?->name]) }}</span>
                                    @if ($piece->motif_rejet)<span class="block text-rouge">{{ __('institutionnel.motif_rejet', ['motif' => $piece->motif_rejet]) }}</span>@endif
                                @else
                                    <span class="texte-carte">{{ __('institutionnel.organisation.non_deposee') }}</span>
                                @endif
                            </td>
                            <td>
                                @if ($piece)
                                    <div class="flex flex-wrap items-center gap-3">
                                        <a href="{{ route('admin.kyb.piece', $piece) }}" class="lien-action" target="_blank">{{ $k['telecharger'] }}</a>
                                        @can(Droit::KybVerifier->value)
                                            @if ($piece->statut === StatutDocumentKyc::EnAttente)
                                                <form method="POST" action="{{ route('admin.kyb.valider', $piece) }}">
                                                    @csrf
                                                    <x-admin.bouton variante="contour" type="submit">{{ $k['valider'] }}</x-admin.bouton>
                                                </form>
                                                <x-admin.confirmation :id="'rejeter-'.$piece->id" :titre="$k['rejeter'].' — '.$type->label()" :message="$k['rejeter_message']"
                                                                      :action="route('admin.kyb.rejeter', $piece)" :bouton="$k['rejeter']" motif danger>
                                                    <x-slot:declencheur>{{ $k['rejeter'] }}</x-slot:declencheur>
                                                </x-admin.confirmation>
                                            @endif
                                        @endcan
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @error('motif') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
    </div>
</x-admin.layout>
