{{--
    Vérification d'identité : statut en tête (toujours écrit), type de titulaire, pièces déposées, formulaire de dépôt.
    Personne physique : CNIB, CIB-AES (recto + verso) ou passeport (une page), avec numéro et date d'expiration, + selfie.
    Personne morale : en plus, RCCM (numéro et document), IFU (numéro et attestation), justificatif de pouvoir ; la pièce
    et le selfie sont ceux du représentant légal. Après un rejet : seules les pièces rejetées ; dossier incomplet : les
    pièces manquantes.
--}}
@php
    use App\Enums\StatutDocumentKyc;
    use App\Enums\StatutKyc;
    use App\Enums\TypeTitulaire;

    $kyc = $user->statut_kyc ?? StatutKyc::NonSoumis;
    $titulaire = $user->type_titulaire ?? TypeTitulaire::Physique;
    $premierDepot = \App\Services\Kyc\VerificationIdentite::premierDepot($user);
    $choixPiece = $premierDepot || ($typePiece === null && in_array('recto', $aFournir, true));
    $titre = __('public.preparation.pages.verification.titre');
    $v = __('public.verification');
    $t = __('public.titulaires');
    $badgeKyc = ['NON_SOUMIS' => 'bg-fond text-encre', 'EN_ATTENTE' => 'bg-jaune-pale text-encre', 'VALIDE' => 'bg-vert-pale text-vert-fonce', 'REJETE' => 'bg-rouge-pale text-rouge', 'A_METTRE_A_JOUR' => 'bg-jaune-pale text-encre'];
    $badgePiece = ['EN_ATTENTE' => 'bg-jaune-pale text-encre', 'VALIDE' => 'bg-vert-pale text-vert-fonce', 'REJETE' => 'bg-rouge-pale text-rouge'];
    $options = ['maxOctets' => 5 * 1024 * 1024, 'messages' => ['format' => __('public.verification.erreurs.fichiers.mimes'), 'taille' => __('public.verification.erreurs.fichiers.max')]];
    $libelle = fn (string $cle, ?string $piece) => in_array($cle, ['recto', 'verso'], true) ? ($v['pieces'][$cle][$piece ?? 'CNIB'] ?? '') : $v['pieces'][$cle];
    $documents = \App\Services\Kyc\VerificationIdentite::DOCUMENTS;
    $titulaireModifiable = in_array($kyc, [StatutKyc::NonSoumis, StatutKyc::AMettreAJour, StatutKyc::Valide], true);
@endphp

<x-public.layout :titre="$titre">
    <x-public.page-entete :titre="$titre" :fil="[['libelle' => __('public.menu.espace'), 'url' => route('espace')], ['libelle' => $titre]]" />

    <div class="conteneur max-w-3xl py-8 tab:py-12">
        {{-- Statut --}}
        <section class="carte p-5" aria-labelledby="statut-titre">
            <div class="flex flex-wrap items-center gap-3">
                <h2 id="statut-titre" class="!text-lg">{{ $v['titre_statut'] }}</h2>
                <span class="rounded-full px-3 py-1 text-sm font-bold {{ $badgeKyc[$kyc->value] }}">{{ __('public.espace.kyc.'.$kyc->value) }}</span>
            </div>
            <p class="mt-2">{{ $v['statut'][$kyc->value] }}</p>
            <p class="mt-2 texte-carte" data-titulaire="{{ $titulaire->value }}">
                {{ __('public.titulaires.actuel', ['type' => $t['types'][$titulaire->value]]) }}@if ($titulaire === TypeTitulaire::Morale && $user->raison_sociale) · <strong>{{ $user->raison_sociale }}</strong>@endif
            </p>
            @if ($kyc === StatutKyc::EnAttente && $aFournir)
                <x-public.alerte variante="attention" class="mt-4" data-a-completer>{{ $v['a_completer'] }}</x-public.alerte>
            @endif
            @if ($kyc === StatutKyc::Valide)
                @if (\App\Support\Phase::presentationActions())
                    <a href="{{ route('souscrire') }}" class="btn-secondaire mt-4">{{ __('public.espace.vides.souscrire') }}</a>
                @else
                    <a href="{{ route('boutiques') }}" class="btn-secondaire mt-4">{{ __('public.boutiques.candidater_boutique') }}</a>
                @endif
            @endif

            @if ($dossier->isNotEmpty())
                <ul class="mt-4 space-y-3 border-t border-ligne pt-4" data-dossier>
                    @foreach ($dossier as $cle => $document)
                        <li class="flex items-start gap-3">
                            @if (str_starts_with((string) $document->mime, 'image/'))
                                <img src="{{ route('espace.verification.fichier', $document) }}" alt="" class="h-16 w-16 shrink-0 rounded-charte border border-ligne object-cover">
                            @else
                                <a href="{{ route('espace.verification.fichier', $document) }}" class="lien h-16 w-16 shrink-0 pt-5 text-center font-bold">PDF</a>
                            @endif
                            <div class="min-w-0">
                                <p class="font-semibold text-encre">{{ $libelle($cle, $typePiece?->value) }}@if ($document->numero) · {{ $document->numero }}@endif</p>
                                <span class="mt-1 inline-block rounded-full px-3 py-0.5 text-sm font-bold {{ $badgePiece[$document->statut->value] }}">{{ $v['statuts_piece'][$document->statut->value] }}</span>
                                @if ($document->statut === StatutDocumentKyc::Rejete && $document->commentaire)
                                    <p class="mt-1 text-[0.95rem] text-rouge">{{ __('public.verification.motif', ['motif' => $document->commentaire]) }}</p>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- Dépôt : dossier complet, pièces rejetées, ou pièces manquantes --}}
        @if ($aFournir)
            <form method="POST" action="{{ route('espace.verification.store') }}" enctype="multipart/form-data" novalidate class="carte mt-6 p-5"
                  x-data="{ piece: @js(old('type_piece', $typePiece?->value ?? 'CNIB')) }" data-depot-kyc>
                @csrf
                <h2 class="!text-lg">{{ $premierDepot ? $v['deposer_titre'] : ($kyc === StatutKyc::Rejete ? $v['renvoyer_titre'] : $v['completer_titre']) }}</h2>
                <p class="mt-1 texte-carte">{{ $v['consignes'] }}</p>
                @if ($titulaire === TypeTitulaire::Morale)
                    <p class="mt-1 texte-carte">{{ $t['representant'] }}</p>
                @endif

                @if ($errors->any())
                    <x-public.alerte variante="danger" class="mt-4" role="alert">{{ $v['erreurs']['resume'] }}</x-public.alerte>
                @endif

                @if ($choixPiece)
                    <fieldset class="mt-5">
                        <legend class="font-semibold text-encre"><x-requis /> {{ $titulaire === TypeTitulaire::Morale ? $v['type_piece_representant'] : $v['type_piece'] }}</legend>
                        <div class="mt-2 grid gap-3 sm:grid-cols-3">
                            @foreach (\App\Enums\TypeDocumentKyc::piecesIdentite() as $type)
                                <label class="flex min-h-[56px] cursor-pointer items-center gap-3 rounded-charte border-2 p-3"
                                       :class="piece === '{{ $type->value }}' ? 'border-vert-fonce bg-vert-pale' : 'border-ligne'">
                                    <input type="radio" name="type_piece" value="{{ $type->value }}" x-model="piece" @checked(old('type_piece', $typePiece?->value ?? 'CNIB') === $type->value) class="h-5 w-5 text-vert focus:ring-0">
                                    <span class="font-semibold text-encre">{{ $v['types'][$type->value] }}</span>
                                </label>
                            @endforeach
                        </div>
                        <x-public.erreur :messages="$errors->get('type_piece')" />
                    </fieldset>
                @endif

                <div class="mt-5 space-y-5">
                    @foreach (['recto', 'verso', 'selfie', 'rccm', 'ifu', 'pouvoir'] as $cle)
                        @if (in_array($cle, $aFournir, true))
                            @php($document = in_array($cle, $documents, true))
                            <div x-data="pieceKyc(@js($options))" @if ($cle === 'verso' && $choixPiece) x-show="piece !== 'PASSEPORT'" @endif data-emplacement="{{ $cle }}">
                                <label for="{{ $cle }}" class="block font-semibold text-encre">
                                    <x-requis />
                                    @if (in_array($cle, ['recto', 'verso'], true) && $choixPiece)
                                        <span x-text="@js($v['pieces'][$cle])[piece] ?? ''">{{ $libelle($cle, null) }}</span>
                                    @else
                                        {{ $libelle($cle, $typePiece?->value) }}
                                    @endif
                                </label>
                                <p id="{{ $cle }}-aide" class="texte-carte">{{ $v['aides'][$cle] }}</p>
                                <input id="{{ $cle }}" name="{{ $cle }}" type="file" @unless ($document) capture="{{ $cle === 'selfie' ? 'user' : 'environment' }}" @endunless
                                       accept="{{ $document ? 'image/jpeg,image/png,application/pdf' : 'image/jpeg,image/png' }}"
                                       @change="choisir($event)" aria-describedby="{{ $cle }}-aide {{ $cle }}-erreur"
                                       @if ($cle === 'verso' && $choixPiece) :disabled="piece === 'PASSEPORT'" @endif
                                       @error($cle) aria-invalid="true" @enderror
                                       class="mt-2 block w-full text-base file:mr-3 file:min-h-[44px] file:rounded-charte file:border-2 file:border-vert-fonce file:bg-white file:px-4 file:font-semibold file:text-vert-fonce">
                                <p x-show="erreur" x-text="erreur" x-cloak class="mt-1.5 font-semibold text-rouge" role="alert"></p>
                                <x-public.erreur :messages="$errors->get($cle)" :id="$cle.'-erreur'" />
                                <img x-show="apercu" :src="apercu" x-cloak alt="{{ $v['apercu'] }}" class="mt-3 max-h-48 rounded-charte border border-ligne">
                            </div>

                            {{-- Numéros et date d'expiration, à côté de la pièce qui les porte. --}}
                            @if ($cle === 'recto')
                                <x-public.champ nom="numero_piece" :libelle="$v['numero_piece']" :aide="$v['aides']['numero_piece']" maxlength="40" autocomplete="off" obligatoire />
                                <div data-expiration-piece>
                                    <label for="piece_expire_le" class="block font-semibold text-encre"><x-requis /> {{ $v['expiration'] }}</label>
                                    <p id="piece_expire_le-aide" class="texte-carte">{{ $v['aides']['expiration'] }}</p>
                                    <input id="piece_expire_le" name="piece_expire_le" type="date" min="{{ now()->addDay()->toDateString() }}" value="{{ old('piece_expire_le') }}"
                                           aria-describedby="piece_expire_le-aide piece_expire_le-erreur" @error('piece_expire_le') aria-invalid="true" @enderror class="champ mt-2">
                                    <x-public.erreur :messages="$errors->get('piece_expire_le')" id="piece_expire_le-erreur" />
                                </div>
                            @elseif ($cle === 'rccm')
                                <x-public.champ nom="numero_rccm" :libelle="$v['numero_rccm']" :aide="$v['aides']['numero_rccm']" maxlength="60" autocomplete="off" obligatoire />
                            @elseif ($cle === 'ifu')
                                <x-public.champ nom="numero_ifu" :libelle="$v['numero_ifu']" :aide="$v['aides']['numero_ifu']" maxlength="40" autocomplete="off" obligatoire />
                            @endif
                        @endif
                    @endforeach
                </div>

                <button type="submit" class="btn-primaire mt-6 w-full sm:w-auto">{{ $v['envoyer'] }}</button>
            </form>
        @endif

        {{-- Type de titulaire : modifiable seulement en repassant par la vérification d'identité. --}}
        @if ($titulaireModifiable)
            <details class="carte mt-6 p-5" @if ($errors->hasAny(['type_titulaire', 'raison_sociale', 'forme_morale'])) open @endif data-changer-titulaire>
                <summary class="cursor-pointer font-semibold text-encre">{{ $t['changer'] }}</summary>
                <p class="mt-2 texte-carte">{{ $t['changer_aide'] }}</p>
                <form method="POST" action="{{ route('espace.verification.titulaire') }}" class="mt-4 space-y-4" x-data="{ type: @js(old('type_titulaire', $titulaire->value)) }">
                    @csrf
                    <fieldset>
                        <legend class="font-semibold text-encre"><x-requis /> {{ $t['titre'] }}</legend>
                        <div class="mt-2 grid gap-3 sm:grid-cols-2">
                            @foreach (TypeTitulaire::cases() as $type)
                                <label class="flex min-h-[56px] cursor-pointer items-center gap-3 rounded-charte border-2 p-3" :class="type === '{{ $type->value }}' ? 'border-vert-fonce bg-vert-pale' : 'border-ligne'">
                                    <input type="radio" name="type_titulaire" value="{{ $type->value }}" x-model="type" @checked(old('type_titulaire', $titulaire->value) === $type->value) class="h-5 w-5 text-vert focus:ring-0">
                                    <span class="font-semibold text-encre">{{ $t['types'][$type->value] }}</span>
                                </label>
                            @endforeach
                        </div>
                        <x-public.erreur :messages="$errors->get('type_titulaire')" />
                    </fieldset>
                    <div x-show="type === 'MORALE'" x-cloak class="space-y-4">
                        <x-public.champ nom="raison_sociale" :libelle="$t['raison_sociale']" :aide="$t['raison_sociale_aide']" :valeur="$user->raison_sociale" maxlength="200" obligatoire />
                        <div>
                            <label for="forme_morale" class="block font-semibold text-encre"><x-requis /> {{ $t['forme'] }}</label>
                            <select id="forme_morale" name="forme_morale" class="champ mt-2">
                                @foreach (TypeTitulaire::FORMES as $forme)
                                    <option value="{{ $forme }}" @selected(old('forme_morale', $user->forme_morale) === $forme)>{{ $t['formes'][$forme] }}</option>
                                @endforeach
                            </select>
                            <x-public.erreur :messages="$errors->get('forme_morale')" />
                        </div>
                    </div>
                    <button type="submit" class="btn-secondaire">{{ $t['changer_bouton'] }}</button>
                </form>
            </details>
        @endif
    </div>
</x-public.layout>
