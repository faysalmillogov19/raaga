{{--
    Paiement d'un objet réservé : souscription d'actions ou caution d'une réservation de boutique.
    États : choix du canal, attente de confirmation (reprise après rechargement), échec (nouvel essai),
    délai dépassé, paiement indisponible. Contexte fourni par le contrôleur : $titre, $fil, $alerte,
    $lignes (récapitulatif), $total, $routes (show, payer, statut, simuler, changer), $nouvelle, $objet.
--}}
@php
    use App\Support\Telephone;

    $s = $objet;
@endphp

<x-public.layout :titre="$titre">
    <x-public.page-entete :titre="$titre" :fil="$fil" />

    <div class="conteneur max-w-3xl py-8 tab:py-12">
        @if ($simule)
            <p class="mb-6 rounded-charte border-2 border-dashed border-jaune bg-jaune-pale px-4 py-3 text-center font-bold text-encre" role="note">
                {{ __('public.paiement.simule') }}
            </p>
        @endif

        @if ($alerte ?? null)
            {{-- Texte des fichiers de langue, référence échappée par le contrôleur. --}}
            <x-public.alerte variante="info" class="mb-6" role="status">{!! $alerte !!}</x-public.alerte>
        @endif

        {{-- Récapitulatif de la réservation --}}
        <section class="carte p-5" aria-labelledby="reservation-titre">
            <h2 id="reservation-titre" class="!text-lg">{{ __('public.paiement.reservation') }}</h2>
            <dl class="mt-3 grid gap-x-6 gap-y-2 text-[0.95rem] tab:grid-cols-2">
                <div class="flex justify-between gap-3"><dt>{{ __('public.paiement.reference') }}</dt><dd class="whitespace-nowrap font-bold tracking-wide text-encre">{{ $s->reference }}</dd></div>
                @foreach ($lignes as [$libelle, $valeur])
                    <div class="flex justify-between gap-3"><dt>{{ $libelle }}</dt><dd class="text-right font-semibold">{{ $valeur }}</dd></div>
                @endforeach
                <div class="flex justify-between gap-3 border-t border-ligne pt-2 tab:col-span-2"><dt class="font-semibold text-encre">{{ __('public.souscription.recap_total') }}</dt><dd class="text-xl font-extrabold text-vert-fonce">{{ format_fcfa($total) }}</dd></div>
            </dl>
            @foreach ($mentions ?? [] as $mention)
                <p class="mt-2 text-sm text-gris">{{ $mention }}</p>
            @endforeach
            @if ($etat !== 'expiree')
                <p class="mt-3 texte-carte">{{ __($avant ?? 'public.paiement.avant', ['date' => $s->echeancePaiement()->translatedFormat('j F Y \à H\hi')]) }}</p>
                @if (($compteRebours ?? false) && $etat !== 'controle')
                    {{-- Compte à rebours du délai de paiement (pré-réservation d'une boutique) ; texte calculé au serveur si JavaScript manque. --}}
                    <p class="compte-rebours mt-2" role="timer" aria-live="off" data-compte-rebours
                       x-data="{ fin: {{ $s->echeancePaiement()->getTimestampMs() }}, maintenant: Date.now(), init() { setInterval(() => this.maintenant = Date.now(), 1000) },
                                 get texte() { const r = Math.max(0, Math.floor((this.fin - this.maintenant) / 1000)); const h = Math.floor(r / 3600), m = Math.floor(r % 3600 / 60), s = r % 60;
                                 return h + ' h ' + String(m).padStart(2, '0') + ' min ' + String(s).padStart(2, '0') + ' s' } }">
                        <span>{{ __('public.paiement.compte_rebours') }}</span>
                        @php($minutes = max(0, (int) now()->diffInMinutes($s->echeancePaiement(), false)))
                        <strong x-text="texte">{{ intdiv($minutes, 60) }} h {{ str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT) }} min</strong>
                    </p>
                @endif
            @endif
        </section>

        <div class="mt-6">
            @switch($etat)
                @case('expiree')
                    <x-etat-vide icone="horloge" :titre="__('public.paiement.expiree_titre')" :lien="$nouvelle['url']" :libelle-lien="$nouvelle['libelle']">
                        {{ __('public.paiement.expiree') }}
                    </x-etat-vide>
                    @break

                @case('indisponible')
                    <x-etat-vide icone="cadenas" :titre="__('public.paiement.indisponible_titre')" :lien="route('contact')" :libelle-lien="__('public.contact.titre')">
                        {{ __('public.paiement.indisponible') }}
                    </x-etat-vide>
                    @break

                @case('manuel')
                @case('controle')
                    @include('public.paiement._manuel')
                    @break

                @case('attente')
                    <section class="carte p-5" aria-labelledby="attente-titre" role="status"
                             x-data="{ url: @js($routes['statut']), init() { this.minuteur = setInterval(() => this.verifier(), 4000) }, async verifier() { try { const r = await (await fetch(this.url, { headers: { Accept: 'application/json' } })).json(); if (r.paiement !== 'EN_ATTENTE' || r.objet !== 'RESERVEE') { clearInterval(this.minuteur); window.location.reload() } } catch { /* Connexion coupée : nouvel essai au prochain passage. */ } } }">
                        <div class="flex items-start gap-3">
                            <span class="mt-1 inline-block h-5 w-5 shrink-0 animate-spin rounded-full border-[3px] border-vert-pale border-t-vert-fonce motion-reduce:animate-none" aria-hidden="true"></span>
                            <div>
                                <h2 id="attente-titre" class="!text-lg">{{ __('public.paiement.attente_titre') }}</h2>
                                <p class="mt-1">{{ __('public.paiement.attente.'.$paiement->canal->famille?->value, ['telephone' => isset($paiement->metadonnees['telephone']) ? Telephone::formaterLocal($paiement->metadonnees['telephone']) : '']) }}</p>
                            </div>
                        </div>

                        @include('public.paiement._instructions', ['canal' => $paiement->canal])

                        <p class="mt-4 texte-carte">{{ __('public.paiement.reprise') }}</p>
                        <div class="mt-4 flex flex-col gap-3 sm:flex-row">
                            <a href="{{ $routes['show'] }}" class="btn-secondaire">{{ __('public.paiement.actualiser') }}</a>
                            <form method="POST" action="{{ $routes['changer'] }}">
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
                                        <form method="POST" action="{{ $routes['simuler'] }}">
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
                        <x-public.alerte variante="danger" :titre="__('public.paiement.echec_titre')" class="mb-6" role="alert">
                            {{ $dernier?->controle === \App\Models\Paiement::REJETE ? __('public.paiement.manuel.rejete', ['motif' => $dernier->controle_motif]) : __('public.paiement.echec') }}
                        </x-public.alerte>
                    @endif

                    <form method="POST" action="{{ $routes['payer'] }}" class="carte p-5" novalidate
                          x-data="{ canal: @js(old('canal', count($canaux) === 1 ? $canaux[0]->value : null)) }">
                        @csrf
                        <fieldset>
                            <legend class="text-lg font-bold text-encre"><x-requis /> {{ __('public.paiement.choisir') }}</legend>
                            <div class="mt-3 grid gap-3 tab:grid-cols-2">
                                @foreach ($canaux as $c)
                                    <label class="flex min-h-[56px] cursor-pointer items-center gap-3 rounded-charte border-2 p-3"
                                           :class="canal === @js($c->value) ? 'border-vert-fonce bg-vert-pale' : 'border-ligne'">
                                        <input type="radio" name="canal" value="{{ $c->value }}" x-model="canal" class="h-5 w-5 text-vert focus:ring-0">
                                        <span class="font-semibold text-encre">{{ $c->label() }}</span>
                                    </label>
                                @endforeach
                            </div>
                            <x-public.erreur :messages="$errors->get('canal')" />
                        </fieldset>

                        @foreach ($canaux as $c)
                            <div x-show="canal === @js($c->value)" x-cloak>
                                @include('public.paiement._instructions', ['canal' => $c])
                                @if ($c->estMobile())
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
