{{-- Souscription : nombre d'actions, quatre engagements (jamais pré-cochés), justificatif si seuil, récapitulatif toujours visible. --}}
@php
    use App\Enums\StatutKyc;

    $t = $tranche;
    $min = max(1, (int) $t->min_actions_par_souscription);
    $reste = $t->plafond_actions_par_personne ? max(0, $t->plafond_actions_par_personne - $deja['actions']) : null;
    $raccourcis = array_values(array_filter([1, 5, 10, 25, 50, 100], fn ($n) => $n >= $min && ($reste === null || $n <= $reste)));
    $nbInitial = (int) old('nb_actions', $reste === 0 ? 0 : $min);
    $kyc = $user->kycValide();
    $parametres = [
        'nb' => $nbInitial, 'min' => $min, 'reste' => $reste, 'prix' => $t->valeur_unitaire,
        'fraisType' => $t->frais_souscription_type->value, 'fraisValeur' => (int) $t->frais_souscription_valeur,
        'seuil' => $t->seuil_kyc_renforce, 'deja' => $deja['montant'], 'justificatifFourni' => $justificatifFourni,
    ];
    $titre = __('public.souscription.titre', ['tranche' => $t->nom]);
@endphp

<x-public.layout :titre="$titre">
    <x-public.page-entete :titre="$titre" :intro="__('public.souscription.intro')" :fil="[['libelle' => __('public.preparation.pages.souscrire.titre')]]" />

    <div class="conteneur pb-48 pt-8 tab:pt-12 lg:pb-12">
        @unless ($kyc)
            <x-public.alerte variante="attention" :titre="__('public.souscription.kyc.titre')" class="mb-6">
                {{ __('public.souscription.kyc.'.($user->statut_kyc ?? StatutKyc::NonSoumis)->value, ['delai' => \App\Models\ParametresPaiements::libelleEnVigueur('kyc')]) }}
                <a href="{{ route('espace.verification') }}" class="font-semibold">{{ __('public.souscription.kyc.lien') }}</a>
            </x-public.alerte>
        @endunless

        @if ($errors->any())
            <x-public.alerte variante="danger" class="mb-6" role="alert">
                <ul class="space-y-1">
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </x-public.alerte>
        @endif

        @if (! $publicAutorise)
            <x-etat-vide icone="lieu" :titre="__('public.souscription.public_refuse')" :lien="route('faq')" :libelle-lien="__('public.etats_vides.liens.faq')">
                {{ __('public.souscription.prix', ['prix' => format_fcfa($t->valeur_unitaire)]) }}
            </x-etat-vide>
        @else
            <form method="POST" action="{{ route('souscrire.store') }}" enctype="multipart/form-data" novalidate
                  x-data="souscription(@js($parametres))" class="nuit-investir grid gap-8 lg:grid-cols-[minmax(0,1fr)_340px]">
                @csrf

                <div class="space-y-8">
                    {{-- 1. Nombre d'actions --}}
                    <section class="carte p-5" aria-labelledby="actions-titre">
                        <h2 id="actions-titre" class="!text-lg">{{ __('public.souscription.etape_actions') }}</h2>
                        <p class="mt-1 texte-carte">{{ __('public.souscription.prix', ['prix' => format_fcfa($t->valeur_unitaire)]) }}</p>

                        <div class="mt-4 flex items-stretch gap-2">
                            <button type="button" class="btn-secondaire min-w-[52px] !px-0 text-2xl" @click="ajuster(-1)" aria-label="{{ __('public.souscription.moins') }}">
                                <span aria-hidden="true" class="inline-block h-0.5 w-4 bg-current"></span>
                            </button>
                            <label for="nb_actions" class="sr-only">{{ __('public.souscription.etape_actions') }}</label>
                            <input id="nb_actions" name="nb_actions" type="text" inputmode="numeric" autocomplete="off"
                                   value="{{ $nbInitial }}" :value="nb" @input="lire($event.target.value)"
                                   class="champ min-w-0 flex-1 text-center text-xl font-extrabold @error('nb_actions') est-invalide @enderror"
                                   aria-describedby="nb-aide" @error('nb_actions') aria-invalid="true" @enderror>
                            <button type="button" class="btn-secondaire min-w-[52px] !px-0" @click="ajuster(1)" aria-label="{{ __('public.souscription.plus') }}">
                                <x-icon name="plus" class="h-5 w-5" />
                            </button>
                        </div>

                        <div id="nb-aide" class="mt-2 space-y-0.5 texte-carte">
                            @if ($min > 1)
                                <p>{{ __('public.souscription.minimum', ['n' => format_nombre($min)]) }}</p>
                            @endif
                            @if ($reste !== null)
                                <p>{{ __('public.souscription.plafond', ['n' => format_nombre($t->plafond_actions_par_personne), 'reste' => format_nombre($reste)]) }}</p>
                            @endif
                            @if ($deja['montant'] > 0)
                                <p>{{ __('public.souscription.deja', ['montant' => format_fcfa($deja['montant'])]) }}</p>
                            @endif
                        </div>

                        @if ($raccourcis)
                            <fieldset class="mt-4">
                                <legend class="text-sm font-semibold text-encre">{{ __('public.souscription.raccourcis') }}</legend>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    @foreach ($raccourcis as $n)
                                        <button type="button" @click="nb = {{ $n }}" :aria-pressed="(nb === {{ $n }}).toString()" aria-pressed="false"
                                                class="min-h-[44px] min-w-[52px] rounded-charte border-2 px-3 font-bold"
                                                :class="nb === {{ $n }} ? 'border-vert-fonce bg-vert-fonce text-white' : 'border-ligne bg-white text-encre'">
                                            {{ trans_choice('public.souscription.raccourci', $n, ['n' => $n]) }}
                                        </button>
                                    @endforeach
                                </div>
                            </fieldset>
                        @endif
                    </section>

                    {{-- 2. Engagements : jamais pré-cochés --}}
                    <section class="carte p-5" aria-labelledby="engagements-titre">
                        <div class="flex items-baseline justify-between gap-3">
                            <h2 id="engagements-titre" class="!text-lg"><x-requis /> {{ __('public.souscription.etape_engagements') }}</h2>
                            <p class="font-bold text-vert-fonce" aria-live="polite"><span x-text="coches.length">0</span>/4</p>
                        </div>
                        <p class="texte-carte">{{ __('public.souscription.engagements_aide') }}</p>

                        <ul class="mt-4 space-y-3">
                            @foreach ($engagements as $document)
                                <li class="rounded-charte border border-ligne p-3">
                                    <label class="flex min-h-[44px] cursor-pointer items-start gap-3">
                                        <input type="checkbox" name="engagements[]" value="{{ $document->value }}" x-model="coches"
                                               class="mt-0.5 h-6 w-6 shrink-0 rounded border-2 border-gris text-vert focus:ring-0">
                                        <span class="font-semibold text-encre">{{ __('public.souscription.engagements.'.$document->value, ['version' => $t->version]) }}</span>
                                    </label>
                                    @if ($document === \App\Enums\DocumentAccepte::ReglementTranche)
                                        <a href="{{ route('souscrire.reglement') }}" target="_blank" rel="noopener" class="ml-9 inline-flex min-h-[44px] items-center">{{ __('public.souscription.lire_reglement') }}</a>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </section>

                    {{-- 3. Justificatif d'origine des fonds (au-delà du seuil de vérification renforcée) --}}
                    @if ($t->seuil_kyc_renforce)
                        @if ($justificatifFourni)
                            <p x-show="{{ $deja['montant'] }} + montant > {{ $t->seuil_kyc_renforce }}" x-cloak class="texte-carte">{{ __('public.souscription.justificatif_fourni') }}</p>
                        @else
                            <section x-show="justificatifRequis" x-cloak class="carte p-5" aria-labelledby="justificatif-titre">
                                <h2 id="justificatif-titre" class="!text-lg">{{ __('public.souscription.justificatif_titre') }}</h2>
                                <p id="justificatif-aide" class="mt-1 texte-carte">{{ __('public.souscription.justificatif_texte', ['seuil' => format_fcfa($t->seuil_kyc_renforce)]) }}</p>
                                <input id="justificatif" name="justificatif" type="file" accept="application/pdf,image/jpeg,image/png"
                                       @change="fichier = $event.target.files.length > 0" aria-describedby="justificatif-aide"
                                       class="mt-3 block w-full text-base file:mr-3 file:min-h-[44px] file:rounded-charte file:border-2 file:border-vert-fonce file:bg-white file:px-4 file:font-semibold file:text-vert-fonce">
                                <x-public.erreur :messages="$errors->get('justificatif')" />
                            </section>
                        @endif
                    @endif
                </div>

                {{-- Récapitulatif : barre fixe en bas sur mobile, colonne collante sur grand écran. --}}
                <aside class="fixed inset-x-0 bottom-0 z-30 border-t border-ligne bg-white p-4 shadow-[0_-4px_16px_rgba(22,32,26,.10)] lg:sticky lg:top-6 lg:self-start lg:rounded-carte lg:border lg:p-5 lg:shadow-legere"
                       aria-labelledby="recap-titre">
                    <h2 id="recap-titre" class="sr-only lg:not-sr-only lg:!text-lg">{{ __('public.souscription.recap') }}</h2>
                    <dl class="hidden space-y-2 text-[0.95rem] lg:mt-3 lg:block">
                        <div class="flex justify-between gap-3"><dt>{{ __('public.souscription.recap_actions') }}</dt><dd class="font-semibold" x-text="valide ? nb : '—'">{{ $nbInitial }}</dd></div>
                        <div class="flex justify-between gap-3"><dt>{{ __('public.souscription.recap_montant') }}</dt><dd class="font-semibold" x-text="fcfa(montant)">{{ format_fcfa($nbInitial * $t->valeur_unitaire) }}</dd></div>
                        <div class="flex justify-between gap-3"><dt>{{ __('public.souscription.recap_frais') }}</dt><dd class="font-semibold" x-text="fcfa(frais)">{{ format_fcfa(\App\Services\Souscriptions\PreparationSouscription::frais($t, $nbInitial * $t->valeur_unitaire)) }}</dd></div>
                    </dl>
                    <div class="flex items-baseline justify-between gap-3 lg:mt-3 lg:border-t lg:border-ligne lg:pt-3">
                        <p class="font-semibold text-encre">{{ __('public.souscription.recap_total') }}
                            <span class="block text-sm font-normal text-gris lg:hidden"><span x-text="valide ? nb : 0">{{ $nbInitial }}</span> × {{ format_fcfa($t->valeur_unitaire) }}</span></p>
                        <p class="text-xl font-extrabold text-vert-fonce" x-text="fcfa(total)" aria-live="polite">{{ format_fcfa($nbInitial * $t->valeur_unitaire + \App\Services\Souscriptions\PreparationSouscription::frais($t, $nbInitial * $t->valeur_unitaire)) }}</p>
                    </div>

                    <button type="submit" class="btn-primaire mt-3 w-full disabled:cursor-not-allowed disabled:opacity-50" :disabled="! pret" disabled>
                        <span>{{ __('public.souscription.continuer') }}</span>
                        <span>(<span x-text="coches.length">0</span>/4)</span>
                    </button>
                    <p class="mt-2 hidden text-sm text-gris lg:block">{{ __('public.souscription.delai', ['delai' => $t->libelleDelaiPaiement()]) }}</p>
                </aside>
            </form>
        @endif
    </div>
</x-public.layout>
