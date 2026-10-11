{{--
    Paiement d'une souscription réservée. États : choix du canal, attente de confirmation (reprise
    après rechargement), échec (nouvel essai), délai dépassé, paiement indisponible.
--}}
@php
    use App\Enums\CanalPaiement;
    use App\Support\Telephone;

    $s = $souscription;
    $total = $s->montant + $s->frais_souscription;
    $titre = __('public.paiement.titre');
    $mobiles = [CanalPaiement::OrangeMoney, CanalPaiement::MoovMoney];
@endphp

<x-public.layout :titre="$titre">
    <x-public.page-entete :titre="$titre" :fil="[['libelle' => __('public.preparation.pages.souscrire.titre'), 'url' => route('souscrire')], ['libelle' => $titre]]" />

    <div class="conteneur max-w-3xl py-8 tab:py-12">
        @if ($simule)
            <p class="mb-6 rounded-charte border-2 border-dashed border-jaune bg-jaune-pale px-4 py-3 text-center font-bold text-encre" role="note">
                {{ __('public.paiement.simule') }}
            </p>
        @endif

        @if (session('souscription.reservee'))
            <x-public.alerte variante="info" class="mb-6" role="status">{!! __('public.souscription.reservee', ['reference' => '<span class="whitespace-nowrap font-bold">'.e($s->reference).'</span>']) !!}</x-public.alerte>
        @endif

        {{-- Récapitulatif de la réservation --}}
        <section class="carte p-5" aria-labelledby="reservation-titre">
            <h2 id="reservation-titre" class="!text-lg">{{ __('public.paiement.reservation') }}</h2>
            <dl class="mt-3 grid gap-x-6 gap-y-2 text-[0.95rem] tab:grid-cols-2">
                <div class="flex justify-between gap-3"><dt>{{ __('public.paiement.reference') }}</dt><dd class="whitespace-nowrap font-bold tracking-wide text-encre">{{ $s->reference }}</dd></div>
                <div class="flex justify-between gap-3"><dt>{{ __('public.souscription.recap_actions') }}</dt><dd class="font-semibold">{{ format_nombre($s->nb_actions_demandees) }}</dd></div>
                <div class="flex justify-between gap-3"><dt>{{ __('public.souscription.recap_montant') }}</dt><dd class="font-semibold">{{ format_fcfa($s->montant) }}</dd></div>
                <div class="flex justify-between gap-3"><dt>{{ __('public.souscription.recap_frais') }}</dt><dd class="font-semibold">{{ format_fcfa($s->frais_souscription) }}</dd></div>
                <div class="flex justify-between gap-3 border-t border-ligne pt-2 tab:col-span-2"><dt class="font-semibold text-encre">{{ __('public.souscription.recap_total') }}</dt><dd class="text-xl font-extrabold text-vert-fonce">{{ format_fcfa($total) }}</dd></div>
            </dl>
            @if ($etat !== 'expiree')
                <p class="mt-3 texte-carte">{{ __('public.paiement.avant', ['date' => $s->expire_le->translatedFormat('j F Y \à H\hi')]) }}</p>
            @endif
        </section>

        <div class="mt-6">
            @switch($etat)
                @case('expiree')
                    <x-etat-vide icone="horloge" :titre="__('public.paiement.expiree_titre')" :lien="route('souscrire')" :libelle-lien="__('public.paiement.nouvelle_souscription')">
                        {{ __('public.paiement.expiree') }}
                    </x-etat-vide>
                    @break

                @case('indisponible')
                    <x-etat-vide icone="cadenas" :titre="__('public.paiement.indisponible_titre')" :lien="route('contact')" :libelle-lien="__('public.contact.titre')">
                        {{ __('public.paiement.indisponible') }}
                    </x-etat-vide>
                    @break

                @case('attente')
                    <section class="carte p-5" aria-labelledby="attente-titre" role="status"
                             x-data="{ url: @js(route('paiement.statut', $s->reference)), init() { this.minuteur = setInterval(() => this.verifier(), 4000) }, async verifier() { try { const r = await (await fetch(this.url, { headers: { Accept: 'application/json' } })).json(); if (r.paiement !== 'EN_ATTENTE' || r.souscription !== 'RESERVEE') { clearInterval(this.minuteur); window.location.reload() } } catch { /* Connexion coupée : nouvel essai au prochain passage. */ } } }">
                        <div class="flex items-start gap-3">
                            <span class="mt-1 inline-block h-5 w-5 shrink-0 animate-spin rounded-full border-[3px] border-vert-pale border-t-vert-fonce motion-reduce:animate-none" aria-hidden="true"></span>
                            <div>
                                <h2 id="attente-titre" class="!text-lg">{{ __('public.paiement.attente_titre') }}</h2>
                                <p class="mt-1">{{ __('public.paiement.attente.'.$paiement->canal->value, ['telephone' => isset($paiement->metadonnees['telephone']) ? Telephone::formaterLocal($paiement->metadonnees['telephone']) : '']) }}</p>
                            </div>
                        </div>

                        @include('public.paiement._instructions', ['canal' => $paiement->canal])

                        <p class="mt-4 texte-carte">{{ __('public.paiement.reprise') }}</p>
                        <div class="mt-4 flex flex-col gap-3 sm:flex-row">
                            <a href="{{ route('paiement.show', $s->reference) }}" class="btn-secondaire">{{ __('public.paiement.actualiser') }}</a>
                            <form method="POST" action="{{ route('paiement.changer', $s->reference) }}">
                                @csrf
                                <button type="submit" class="btn-secondaire w-full">{{ __('public.paiement.changer') }}</button>
                            </form>
                        </div>

                        @if ($simule)
                            <div class="mt-6 rounded-charte border-2 border-dashed border-jaune p-4">
                                <p class="font-bold text-encre">{{ __('public.paiement.simulateur') }}</p>
                                <p class="texte-carte">{{ __('public.paiement.simulateur_aide') }}</p>
                                <div class="mt-3 flex flex-col gap-3 sm:flex-row">
                                    @foreach (['succes', 'echec'] as $issue)
                                        <form method="POST" action="{{ route('paiement.simuler', $s->reference) }}">
                                            @csrf
                                            <input type="hidden" name="issue" value="{{ $issue }}">
                                            <button type="submit" class="{{ $issue === 'succes' ? 'btn-primaire' : 'btn-secondaire' }} w-full">{{ __('public.paiement.simuler_'.$issue) }}</button>
                                        </form>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </section>
                    @break

                @default
                    @if ($etat === 'echec')
                        <x-public.alerte variante="danger" :titre="__('public.paiement.echec_titre')" class="mb-6" role="alert">{{ __('public.paiement.echec') }}</x-public.alerte>
                    @endif

                    <form method="POST" action="{{ route('paiement.payer', $s->reference) }}" class="carte p-5" novalidate
                          x-data="{ canal: @js(old('canal', count($canaux) === 1 ? $canaux[0]->value : null)) }">
                        @csrf
                        <fieldset>
                            <legend class="text-lg font-bold text-encre">{{ __('public.paiement.choisir') }}</legend>
                            <div class="mt-3 grid gap-3 tab:grid-cols-2">
                                @foreach ($canaux as $c)
                                    <label class="flex min-h-[56px] cursor-pointer items-center gap-3 rounded-charte border-2 p-3"
                                           :class="canal === @js($c->value) ? 'border-vert-fonce bg-vert-pale' : 'border-ligne'">
                                        <input type="radio" name="canal" value="{{ $c->value }}" x-model="canal" class="h-5 w-5 text-vert focus:ring-0">
                                        <span class="font-semibold text-encre">{{ __('public.paiement.canaux.'.$c->value) }}</span>
                                    </label>
                                @endforeach
                            </div>
                            <x-public.erreur :messages="$errors->get('canal')" />
                        </fieldset>

                        @foreach ($canaux as $c)
                            <div x-show="canal === @js($c->value)" x-cloak>
                                @include('public.paiement._instructions', ['canal' => $c])
                                @if (in_array($c, $mobiles, true))
                                    <x-public.champ nom="telephone" type="tel" class="mt-4" :id="'telephone-'.$c->value"
                                                    :libelle="__('public.paiement.numero', ['operateur' => $c->label()])" :aide="__('public.paiement.numero_aide')"
                                                    :valeur="$s->user->telephone ? Telephone::formaterLocal($s->user->telephone) : null"
                                                    inputmode="tel" autocomplete="tel-national" ::disabled="canal !== @js($c->value)" />
                                @endif
                            </div>
                        @endforeach

                        <button type="submit" class="btn-primaire mt-5 w-full disabled:cursor-not-allowed disabled:opacity-50" :disabled="! canal" @disabled(count($canaux) !== 1)>
                            {{ $etat === 'echec' ? __('public.paiement.reessayer') : __('public.paiement.payer', ['montant' => format_fcfa($total)]) }}
                        </button>
                    </form>
            @endswitch
        </div>

        @if ($antiFraude = plateforme('messages.messageAntiFraude'))
            <x-public.alerte variante="attention" class="mt-8">{{ $antiFraude }}</x-public.alerte>
        @endif
    </div>
</x-public.layout>
