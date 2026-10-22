{{--
    « Investir dans Raaga » (démarche Investir, accent or) : comment ça marche, les dividendes, les risques, le
    calendrier, les documents et une FAQ dédiée. Prix (tranche ouverte) ou valeur indicative (sinon), minimum et taux
    IRVM lus dans la tranche et les paramètres (RaagaInvest::conditions()), jamais écrits en dur.
--}}
@php($o = __('public.investir'))

<x-public.layout :titre="$o['titre']">
    <x-public.page-entete :titre="$o['titre']" :intro="$o['intro']" :fil="[['libelle' => $o['titre']]]" />

    <div class="conteneur grid grid-cols-[minmax(0,1fr)] gap-8 py-8 tab:py-12 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)] lg:gap-12">
        <div class="space-y-10">
            {{-- RAAGA Invest : mentions obligatoires. --}}
            <x-public.mentions-invest />

            {{-- Comment ça marche : 4 étapes --}}
            <section aria-labelledby="etapes-titre">
                <h2 id="etapes-titre">{{ $o['etapes']['titre'] }}</h2>
                <ol class="mt-4 grid gap-3 sm:grid-cols-2">
                    @foreach (['compte', 'nombre', 'paiement', 'attestation'] as $i => $cle)
                        <li class="carte demarche-investir flex gap-3 p-4">
                            <span class="badge-demarche !px-3 text-base" aria-hidden="true">{{ $i + 1 }}</span>
                            <p class="leading-relaxed"><span class="sr-only">{{ $i + 1 }}. </span>{{ $o['etapes'][$cle] }}</p>
                        </li>
                    @endforeach
                </ol>
            </section>

            {{-- Les dividendes : origine, décision en assemblée générale, versement après IRVM --}}
            <section aria-labelledby="dividendes-titre">
                <h2 id="dividendes-titre">{{ $o['dividendes']['titre'] }}</h2>
                <ul class="mt-4 grid gap-3 sm:grid-cols-3">
                    @foreach (['loyers' => 'boutique-auvent', 'parkings' => 'parking', 'services' => 'services'] as $cle => $picto)
                        <li class="carte demarche-investir flex items-center gap-3 p-4">
                            <span class="picto-demarche"><x-icon :name="$picto" class="h-6 w-6" /></span>
                            <span class="font-semibold text-encre">{{ __('public.demarches.origine.sources.'.$cle) }}</span>
                        </li>
                    @endforeach
                </ul>
                <p class="mt-4 leading-relaxed">{{ $o['dividendes']['decision'] }}</p>
                <p class="mt-2 leading-relaxed">{{ $irvm ? __('public.investir.dividendes.versement_taux', ['taux' => $irvm]) : $o['dividendes']['versement'] }}</p>
            </section>

            {{-- Les risques --}}
            <section aria-labelledby="risques-titre">
                <h2 id="risques-titre">{{ $o['risques']['titre'] }}</h2>
                <x-public.alerte variante="attention" :titre="$o['risques']['alerte']" class="mt-4">
                    <ul class="list-disc space-y-1.5 pl-5">
                        @foreach ($o['risques']['liste'] as $risque)
                            <li>{{ $risque }}</li>
                        @endforeach
                    </ul>
                </x-public.alerte>
            </section>

            {{-- Calendrier --}}
            <section aria-labelledby="calendrier-titre">
                <h2 id="calendrier-titre">{{ __('public.offre.calendrier.titre') }}</h2>
                <x-public.frise class="mt-5" :etapes="$etapes" />
            </section>

            {{-- Manifestation d'intérêt : citoyen connecté, démarche non engageante, aucun paiement. --}}
            @if ($interet)
                @php($v = __('public.invest'))
                <section id="interet" class="carte p-5 tab:p-6" aria-labelledby="interet-titre">
                    <h2 id="interet-titre">{{ $v['interet']['titre'] }}</h2>
                    <p class="mt-2">{{ $v['interet']['intro'] }}</p>
                    @if ($citoyen)
                        <form method="POST" action="{{ route('invest.interet') }}" class="mt-4 space-y-4" data-formulaire-interet>
                            @csrf
                            <fieldset>
                                <legend class="font-bold text-encre"><x-requis /> {{ $v['attributs']['profil'] }}</legend>
                                <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                    @foreach ($profils as $profil)
                                        <label class="inline-flex min-h-[44px] items-center gap-3">
                                            <input type="radio" name="profil" value="{{ $profil->value }}" @checked(old('profil') === $profil->value) required> {{ $profil->label() }}
                                        </label>
                                    @endforeach
                                </div>
                                @error('profil') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
                            </fieldset>
                            <x-public.champ nom="pays_residence" :libelle="ucfirst($v['attributs']['pays_residence'])" :valeur="old('pays_residence', 'Burkina Faso')" maxlength="80" required />
                            <x-public.champ nom="nombre_actions" :libelle="ucfirst($v['attributs']['nombre_actions'])" type="number" min="1" max="10000000" :valeur="old('nombre_actions', $nombreMinimum)" required />
                            <div>
                                <x-public.case nom="simulation_comprise" :libelle="$v['interet']['case']" value="1" required />
                                @error('simulation_comprise') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
                            </div>
                            <button type="submit" class="btn-primaire">{{ $v['interet']['envoyer'] }}</button>
                            <p class="texte-carte">{{ $v['interet']['aucun_paiement'] }}</p>
                        </form>
                    @else
                        <p class="mt-3"><a href="{{ route('connexion') }}" class="lien-fort">{{ $v['interet']['se_connecter'] }}</a></p>
                    @endif
                </section>
            @endif

            {{-- FAQ dédiée --}}
            <section aria-labelledby="faq-investir-titre">
                <h2 id="faq-investir-titre">{{ $o['faq'] }}</h2>
                <div class="faq-liste">
                    @foreach ($questions as $question)
                        <x-public.question :question="$question['q']">{{ $question['r'] }}</x-public.question>
                    @endforeach
                </div>
            </section>
        </div>

        {{-- Encart : documents et action --}}
        <aside class="lg:sticky lg:top-6 lg:self-start" aria-labelledby="documents-titre">
            <div class="carte demarche-investir entete-demarche p-5">
                <h2 id="documents-titre" class="!text-lg">{{ $o['documents'] }}</h2>
                <ul class="mt-3 divide-y divide-ligne">
                    @foreach ($documents as $document)
                        <li class="flex items-center gap-3 py-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-charte bg-[#FFF6CC] text-xs font-bold text-encre" aria-hidden="true">{{ __('public.communs.pdf') }}</span>
                            <div class="min-w-0">
                                @if ($document['url'])
                                    <a href="{{ $document['url'] }}" class="lien">{{ __('public.documents.'.$document['cle']) }}</a>
                                @else
                                    <p class="font-semibold text-encre">{{ __('public.documents.'.$document['cle']) }}</p>
                                    <p class="texte-carte">{{ __('public.communs.bientot_disponible') }}</p>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>

                <div class="mt-4 border-t border-ligne pt-4">
                    @if ($conditions)
                        {{-- « Prix de l'action » seulement quand une tranche est ouverte ; sinon valeur indicative des paramètres. --}}
                        <p class="font-bold text-encre" data-conditions="{{ $conditions['ouverte'] ? 'tranche' : 'indicative' }}">{{ __($conditions['ouverte'] ? 'public.demarches.investir.prix' : 'public.demarches.investir.valeur_indicative', ['prix' => format_fcfa($conditions['prix'])]) }}</p>
                        <p class="texte-carte">{{ trans_choice('public.demarches.investir.minimum', $conditions['minimum'], ['n' => format_nombre($conditions['minimum'])]) }}</p>
                        @unless ($conditions['ouverte'])
                            <p class="mt-1 font-semibold text-encre" data-souscription-fermee>{{ __('public.demarches.investir.fermee') }}</p>
                        @endunless
                    @endif
                    <a href="{{ $urlSouscrire }}" class="btn-demarche mt-3 w-full">{{ \App\Support\Phase::souscriptionOuverte() ? __('public.demarches.investir.bouton') : __('public.demarches.bientot') }}</a>
                </div>

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
