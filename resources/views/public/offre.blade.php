<x-public.layout :titre="__('public.offre.titre')">
    <x-public.page-entete :titre="__('public.offre.titre')" :intro="__('public.offre.intro')"
                          :fil="[['libelle' => __('public.offre.titre')]]" />

    <div class="conteneur grid grid-cols-[minmax(0,1fr)] gap-8 py-8 tab:py-12 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)] lg:gap-12">
        <div class="space-y-10">

            {{-- Qu'est-ce qu'une action populaire ? --}}
            <section aria-labelledby="action-titre">
                <h2 id="action-titre">{{ __('public.offre.action.titre') }}</h2>
                <p class="mt-3 leading-relaxed">{{ __('public.offre.action.texte') }}</p>
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div class="carte p-5">
                        <h3>{{ __('public.offre.action.a_titre') }}</h3>
                        <p class="mt-1 text-[0.95rem] leading-relaxed">{{ __('public.offre.action.a_texte') }}</p>
                    </div>
                    <div class="carte border-2 !border-vert p-5">
                        <h3 class="!text-vert-fonce">{{ __('public.offre.action.b_titre') }}</h3>
                        <p class="mt-1 text-[0.95rem] leading-relaxed">{{ __('public.offre.action.b_texte') }}</p>
                    </div>
                </div>
            </section>

            {{-- Ce que vous obtenez --}}
            <section aria-labelledby="obtenez-titre">
                <h2 id="obtenez-titre">{{ __('public.offre.obtenez.titre') }}</h2>
                <ul class="mt-4 space-y-3">
                    @foreach (__('public.offre.obtenez.liste') as $element)
                        <li class="flex gap-3">
                            <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-vert-pale text-vert-fonce" aria-hidden="true">
                                <x-public.icone nom="check" class="h-4 w-4" />
                            </span>
                            <span class="leading-relaxed">{{ $element }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>

            {{-- Ce que vous n'obtenez pas --}}
            <section aria-labelledby="obtenez-pas-titre">
                <h2 id="obtenez-pas-titre">{{ __('public.offre.obtenez_pas.titre') }}</h2>
                <x-public.alerte variante="danger" :titre="__('public.offre.obtenez_pas.alerte')" class="mt-4">
                    <ul class="list-disc space-y-1.5 pl-5">
                        @foreach (__('public.offre.obtenez_pas.liste') as $element)
                            <li>{{ $element }}</li>
                        @endforeach
                    </ul>
                </x-public.alerte>
            </section>

            {{-- D'où viendront les bénéfices ? --}}
            <section aria-labelledby="benefices-titre">
                <h2 id="benefices-titre">{{ __('public.offre.benefices.titre') }}</h2>
                <p class="mt-3 leading-relaxed">{{ __('public.offre.benefices.texte', ['boutiques' => format_nombre((int) plateforme('projet.nombreBoutiques'))]) }}</p>
            </section>

            {{-- Calendrier --}}
            <section aria-labelledby="calendrier-titre">
                <h2 id="calendrier-titre">{{ __('public.offre.calendrier.titre') }}</h2>
                <x-public.frise class="mt-5" :etapes="$etapes" />
            </section>

            {{-- Exemple chiffré --}}
            @if ($etat)
                @php
                    $nb = 25;
                    $dividende = 500;
                @endphp
                <section aria-labelledby="exemple-titre">
                    <h2 id="exemple-titre">{{ __('public.offre.exemple.titre') }}</h2>
                    <div class="carte mt-4 space-y-3 p-5 leading-relaxed">
                        <p>{{ __('public.offre.exemple.achat', ['nb' => $nb, 'prix' => format_fcfa($etat->valeurUnitaire), 'total' => format_fcfa($nb * $etat->valeurUnitaire)]) }}</p>
                        <p>{{ __('public.offre.exemple.dividende', ['nb' => $nb, 'dividende' => format_fcfa($dividende), 'gain' => format_fcfa($nb * $dividende)]) }}</p>
                        <x-public.alerte variante="attention">{{ __('public.offre.exemple.avertissement') }}</x-public.alerte>
                    </div>
                </section>
            @endif
        </div>

        {{-- Encart --}}
        <aside class="lg:sticky lg:top-6 lg:self-start" aria-labelledby="documents-titre">
            <div class="carte p-5">
                <h2 id="documents-titre" class="!text-lg">{{ __('public.documents.titre') }}</h2>
                <ul class="mt-3 divide-y divide-ligne">
                    @foreach ($documents as $document)
                        <li class="flex items-center gap-3 py-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-charte bg-rouge-pale text-xs font-bold text-rouge" aria-hidden="true">{{ __('public.communs.pdf') }}</span>
                            <div class="min-w-0">
                                @if ($document['url'])
                                    <a href="{{ $document['url'] }}" class="lien" download>{{ __('public.documents.'.$document['cle']) }}</a>
                                @else
                                    <p class="font-semibold text-encre">{{ __('public.documents.'.$document['cle']) }}</p>
                                    <p class="texte-carte">
                                        @if ($document['cle'] === 'reglement'){{ __('public.documents.reglement_detail') }} · @endif{{ __('public.communs.bientot_disponible') }}
                                    </p>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>

                @if ($etat)
                    <div class="mt-4 border-t border-ligne pt-4">
                        <p class="font-bold text-encre">{{ __('public.offre.encart.pret') }}</p>
                        <p class="mt-1 texte-carte">{{ __('public.offre.encart.texte') }}</p>
                        <a href="{{ $urlSouscrire }}" class="btn-primaire mt-3 w-full">{{ __('public.communs.je_souscris') }} <x-public.icone nom="fleche" /></a>
                    </div>
                @endif

                @if ($numeroVert = plateforme('contacts.numeroVert'))
                    <div class="mt-4 flex items-start gap-3 rounded-charte bg-vert-pale p-4">
                        <x-public.icone nom="telephone" class="mt-0.5 h-5 w-5 text-vert-fonce" />
                        <div>
                            <p class="texte-carte">{{ __('public.communs.numero_vert') }}</p>
                            <p class="text-lg font-extrabold text-vert-fonce">{{ \App\Support\Telephone::formaterLocal($numeroVert) }}</p>
                        </div>
                    </div>
                @endif
            </div>
        </aside>
    </div>
</x-public.layout>
