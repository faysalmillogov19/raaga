{{-- Mon espace, onglet « Mes actions » : attestations, reçus et règlements acceptés. --}}
                    @if ($recus->isEmpty() && $reglements->isEmpty() && $attestations->isEmpty())
                        <x-etat-vide icone="contenus" :titre="__('public.espace.vides.documents_titre')">
                            {{ __('public.espace.vides.documents') }}
                        </x-etat-vide>
                    @else
                        <ul class="space-y-3">
                            @foreach ($attestations as $ligne)
                                <li class="carte flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
                                    <div><p class="font-semibold text-encre">{{ __('public.espace.documents.attestation', ['n' => format_nombre($ligne->nb_actions)]) }}</p>
                                        <p class="texte-carte">{{ $ligne->tranche->nom }} · {{ __('public.espace.documents.certificat', ['numero' => $ligne->numero_certificat]) }} · {{ $ligne->date_inscription->format('d/m/Y') }}</p></div>
                                    <a href="{{ route('espace.documents.attestation', $ligne->numero_certificat) }}" class="btn-secondaire" download>{{ __('public.espace.documents.telecharger') }}</a>
                                </li>
                            @endforeach
                            @foreach ($recus as $r)
                                <li class="carte flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
                                    <div><p class="font-semibold text-encre">{{ __('public.espace.documents.recu', ['numero' => \App\Support\Recu::numero($r['paiement'])]) }}</p>
                                        <p class="texte-carte">{{ $r['souscription']->reference }} · {{ $r['paiement']->date_confirmation->format('d/m/Y') }}</p></div>
                                    <a href="{{ route('paiement.recu', $r['souscription']->reference) }}" class="btn-secondaire" download>{{ __('public.espace.documents.telecharger') }}</a>
                                </li>
                            @endforeach
                            @foreach ($reglements as $a)
                                <li class="carte flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
                                    <div><p class="font-semibold text-encre">{{ __('public.espace.documents.reglement', ['tranche' => $a->souscription->tranche->nom, 'version' => $a->version_document]) }}</p>
                                        <p class="texte-carte">{{ __('public.espace.documents.accepte_le', ['date' => $a->accepte_le->format('d/m/Y')]) }}</p></div>
                                    <a href="{{ route('espace.documents.reglement', $a->souscription->reference) }}" class="btn-secondaire">{{ __('public.espace.documents.consulter') }}</a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
