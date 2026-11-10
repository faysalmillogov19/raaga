{{--
    Vérification d'identité : statut en tête (toujours écrit), type de titulaire, pièces déposées, formulaire de dépôt.
    Personne physique : CNIB, CIB-AES (recto + verso) ou passeport (une page), avec numéro et date d'expiration, + selfie.
    Personne morale : en plus, IFU (numéro et attestation), justificatif de pouvoir ; la pièce et le selfie sont ceux du
    représentant légal. Après un rejet : seules les pièces rejetées ; dossier incomplet : les
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
    // Formalisation demandée dans le dépôt (personne physique, premier dépôt, 10/11/2026) : IFU et RCCM dans son bloc.
    $blocFormalisation = $aFournir && \App\Services\Kyc\VerificationIdentite::formalisationDemandee($user);
    $questionFormalisation = \App\Services\Kyc\VerificationIdentite::questionFormalisation($user);
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
                @if ($retour)
                    <input type="hidden" name="retour" value="{{ $retour }}">
                @endif
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
                    @foreach (['recto', 'verso', 'selfie', 'ifu', 'pouvoir', 'rccm'] as $cle)
                        @if (in_array($cle, $aFournir, true) && ! ($blocFormalisation && in_array($cle, ['ifu', 'rccm'], true)))
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
                                {{-- Valeurs déjà connues du compte reprises (05/11/2026) ; date d'expiration seulement si elle est à venir. --}}
                                {{-- CNIB ou CIB-AES : NIP (06/11/2026) ; un ancien numéro de carte n'est pas repris, le NIP est demandé. --}}
                                <x-public.champ nom="numero_piece" :libelle="$v['numero_piece']" :aide="$v['aides']['numero_piece']" nip
                                    :valeur="\App\Support\Nip::porteNip($typePiece ?? \App\Enums\TypeDocumentKyc::Cnib) && ! \App\Support\Nip::valide($user->numero_piece) ? null : $user->numero_piece" maxlength="40" autocomplete="off" obligatoire />
                                <div data-expiration-piece>
                                    <label for="piece_expire_le" class="block font-semibold text-encre"><x-requis /> {{ $v['expiration'] }}</label>
                                    <p id="piece_expire_le-aide" class="texte-carte">{{ $v['aides']['expiration'] }}</p>
                                    <input id="piece_expire_le" name="piece_expire_le" type="date" min="{{ now()->addDay()->toDateString() }}" value="{{ old('piece_expire_le', $user->piece_expire_le?->gt(today()) ? $user->piece_expire_le->toDateString() : null) }}"
                                           aria-describedby="piece_expire_le-aide piece_expire_le-erreur" @error('piece_expire_le') aria-invalid="true" @enderror class="champ mt-2">
                                    <x-public.erreur :messages="$errors->get('piece_expire_le')" id="piece_expire_le-erreur" />
                                </div>
                                {{-- Sexe et lieu de naissance, tels qu'inscrits sur la pièce (carte de commerçant). --}}
                                <fieldset data-sexe>
                                    <legend class="font-semibold text-encre"><x-requis /> {{ $v['sexe'] }}</legend>
                                    <div class="mt-2 flex flex-wrap gap-3">
                                        @foreach (\App\Models\User::SEXES as $sexe)
                                            <label class="flex min-h-[48px] cursor-pointer items-center gap-3 rounded-charte border-2 border-ligne px-4">
                                                <input type="radio" name="sexe" value="{{ $sexe }}" @checked(old('sexe', $user->sexe) === $sexe) class="h-5 w-5 text-vert focus:ring-0">
                                                <span class="font-semibold text-encre">{{ $v['sexes'][$sexe] }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                    <x-public.erreur :messages="$errors->get('sexe')" />
                                </fieldset>
                                <x-public.champ nom="lieu_naissance" :libelle="$v['lieu_naissance']" :aide="$v['aides']['lieu_naissance']" :valeur="$user->lieu_naissance" maxlength="100" autocomplete="off" obligatoire />
                            @endif
                            @if ($cle === 'ifu')
                                <x-public.champ nom="numero_ifu" :libelle="$v['numero_ifu']" :aide="$v['aides']['numero_ifu']" :valeur="$user->ifu" maxlength="30" autocomplete="off" autocapitalize="characters" />
                            @endif
                        @endif
                    @endforeach
                </div>

                {{-- Formalisation (10/11/2026) : personne physique au premier dépôt. « Oui » (ou IFU, RCCM déjà connus) : IFU et RCCM
                     demandés ; « Non » : préréservation possible, formalisation ensuite dans le délai du récépissé. --}}
                @if ($blocFormalisation)
                    @php($fv = __('public.verification.formalisation'))
                    @php($rc = __('public.rccm'))
                    <fieldset class="mt-6 border-t border-ligne pt-5" x-data="{ formalisee: @js((string) old('formalisee', $questionFormalisation ? '' : 'oui')) }" data-formalisation>
                        <legend class="font-semibold text-encre">@if ($questionFormalisation)<x-requis /> @endif{{ $fv['question'] }}</legend>
                        <p class="texte-carte">{{ $questionFormalisation ? $fv['aide'] : $fv['deja'] }}</p>
                        @if ($questionFormalisation)
                            <div class="mt-2 grid gap-3 sm:grid-cols-2">
                                @foreach (['oui', 'non'] as $reponse)
                                    <label class="flex min-h-[56px] cursor-pointer items-center gap-3 rounded-charte border-2 p-3"
                                           :class="formalisee === '{{ $reponse }}' ? 'border-vert-fonce bg-vert-pale' : 'border-ligne'">
                                        <input type="radio" name="formalisee" value="{{ $reponse }}" x-model="formalisee" @checked(old('formalisee') === $reponse) class="h-5 w-5 text-vert focus:ring-0" data-formalisee="{{ $reponse }}">
                                        <span class="font-semibold text-encre">{{ $fv[$reponse] }}</span>
                                    </label>
                                @endforeach
                            </div>
                            <x-public.erreur :messages="$errors->get('formalisee')" />
                            <div class="mt-4" x-show="formalisee === 'non'" @if (old('formalisee') !== 'non') x-cloak @endif>
                                @include('public.partials.demarche-formalisation')
                            </div>
                        @else
                            <input type="hidden" name="formalisee" value="oui">
                        @endif

                        <div class="mt-5 space-y-5" x-show="formalisee === 'oui'" @if ($questionFormalisation && old('formalisee') !== 'oui') x-cloak @endif data-informations-formalisation>
                            <h3 class="font-bold text-encre">{{ $fv['ifu_titre'] }}</h3>
                            <x-public.champ nom="numero_ifu" :libelle="$v['numero_ifu']" :aide="$fv['ifu_aide']" :valeur="$user->ifu" maxlength="30" autocomplete="off" autocapitalize="characters" obligatoire />
                            <div>
                                <label for="ifu" class="block font-semibold text-encre"><x-requis /> {{ $v['pieces']['ifu'] }}</label>
                                <p id="ifu-aide" class="texte-carte">{{ $v['aides']['ifu'] }}</p>
                                <input id="ifu" name="ifu" type="file" accept="image/jpeg,image/png,application/pdf" aria-describedby="ifu-aide ifu-erreur" @error('ifu') aria-invalid="true" @enderror
                                       class="mt-2 block w-full text-base file:mr-3 file:min-h-[44px] file:rounded-charte file:border-2 file:border-vert-fonce file:bg-white file:px-4 file:font-semibold file:text-vert-fonce">
                                <x-public.erreur :messages="$errors->get('ifu')" id="ifu-erreur" />
                            </div>

                            <h3 class="font-bold text-encre">{{ __('public.verification.rccm.titre') }}</h3>
                            {{-- Aide au-dessus des deux champs : numéro et date restent au même niveau. --}}
                            <p class="texte-carte">{{ $rc['numero_aide'] }}</p>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <x-public.champ nom="rccm" :libelle="$rc['numero']" :valeur="$user->rccm" maxlength="60" autocomplete="off" autocapitalize="characters" obligatoire />
                                <x-public.champ nom="rccm_immatricule_le" type="date" :libelle="$rc['date']" :valeur="$user->rccm_immatricule_le?->toDateString()" max="{{ now()->toDateString() }}" obligatoire />
                            </div>
                            @include('public.partials.champ-greffe', ['valeurId' => old('rccm_greffe_id', $user->rccm_greffe_id), 'valeurAutre' => old('rccm_greffe_autre', $user->rccm_greffe_autre), 'classeLibelle' => 'block font-semibold text-encre', 'obligatoire' => true])
                            <div>
                                <label for="justificatif_rccm" class="block font-semibold text-encre"><x-requis /> {{ $rc['document'] }}</label>
                                <p id="justificatif_rccm-aide" class="texte-carte">{{ __('public.verification.rccm.document_aide') }}</p>
                                <input id="justificatif_rccm" name="justificatif_rccm" type="file" accept="image/jpeg,image/png,application/pdf" aria-describedby="justificatif_rccm-aide justificatif_rccm-erreur"
                                       @error('justificatif_rccm') aria-invalid="true" @enderror class="mt-2 block w-full text-base file:mr-3 file:min-h-[44px] file:rounded-charte file:border-2 file:border-vert-fonce file:bg-white file:px-4 file:font-semibold file:text-vert-fonce">
                                <x-public.erreur :messages="$errors->get('justificatif_rccm')" id="justificatif_rccm-erreur" />
                            </div>
                        </div>
                    </fieldset>
                @endif

                <button type="submit" class="btn-primaire mt-6 w-full sm:w-auto">{{ $v['envoyer'] }}</button>
            </form>
        @endif

        {{-- Votre IFU (10/11/2026) : personne physique pas encore formalisée, après le premier dépôt ; vérifié par l'ADMIN, la
             carte de commerçant est émise ensuite. --}}
        @if (\App\Services\Kyc\VerificationIdentite::ifuADeposer($user))
            @php($vi = __('public.verification.ifu'))
            @php($attestation = $user->kycDocuments()->where('type', \App\Enums\TypeDocumentKyc::Ifu)->latest('id')->first())
            <form method="POST" action="{{ route('espace.verification.ifu') }}" enctype="multipart/form-data" novalidate class="carte mt-6 p-5" id="ifu" data-ifu-formalisation>
                @csrf
                <h2 class="!text-lg">{{ $vi['titre'] }}</h2>
                <p class="texte-carte">{{ $vi['aide'] }}</p>
                @if ($attestation && $attestation->numero === $user->ifu)
                    <p class="mt-3 font-semibold text-encre" data-statut-ifu>{{ $vi['statuts'][$attestation->statut->value] }}</p>
                    @if ($attestation->statut === StatutDocumentKyc::Rejete && $attestation->commentaire)
                        <p class="texte-carte">{{ $vi['motif'] }} {{ $attestation->commentaire }}</p>
                    @endif
                @endif
                <div class="mt-4 space-y-4">
                    <x-public.champ nom="numero_ifu" id="numero_ifu_formalisation" :libelle="$v['numero_ifu']" :aide="__('public.verification.formalisation.ifu_aide')" :valeur="$user->ifu"
                                    maxlength="30" autocomplete="off" autocapitalize="characters" obligatoire />
                    <div>
                        <label for="attestation_ifu" class="block font-semibold text-encre"><x-requis /> {{ $v['pieces']['ifu'] }}</label>
                        <p id="attestation_ifu-aide" class="texte-carte">{{ $v['aides']['ifu'] }}</p>
                        <input id="attestation_ifu" name="attestation_ifu" type="file" accept="image/jpeg,image/png,application/pdf" aria-describedby="attestation_ifu-aide attestation_ifu-erreur"
                               @error('attestation_ifu') aria-invalid="true" @enderror class="mt-2 block w-full text-base file:mr-3 file:min-h-[44px] file:rounded-charte file:border-2 file:border-vert-fonce file:bg-white file:px-4 file:font-semibold file:text-vert-fonce">
                        <x-public.erreur :messages="$errors->get('attestation_ifu')" id="attestation_ifu-erreur" />
                    </div>
                </div>
                <button type="submit" class="btn-secondaire mt-4">{{ $vi['envoyer'] }}</button>
            </form>
        @endif

        {{-- Type de titulaire : modifiable seulement en repassant par la vérification d'identité. --}}
        {{-- RCCM (05/11/2026) : obligatoire pour une personne morale, facultatif pour une personne physique ; modifiable à tout
             moment ; un nouveau justificatif est vérifié par l'ADMIN ; jamais imprimé sur la carte. --}}
        @php($rc = __('public.rccm'))
        @php($rv = __('public.verification.rccm'))
        @unless ($blocFormalisation)
        <form method="POST" action="{{ route('espace.verification.rccm') }}" enctype="multipart/form-data" novalidate class="carte mt-6 p-5" id="rccm" data-rccm>
            @csrf
            <h2 class="!text-lg">{{ $rv['titre'] }}</h2>
            <p class="texte-carte">{{ $titulaire === TypeTitulaire::Morale ? $rv['aide_morale'] : $rv['aide_physique'] }}</p>
            @if ($justificatifRccm && $justificatifRccm->numero === $user->rccm)
                <p class="mt-3 font-semibold text-encre" data-statut-rccm>{{ $rv['statuts'][$justificatifRccm->statut->value] }}</p>
                @if ($justificatifRccm->statut === StatutDocumentKyc::Rejete && $justificatifRccm->commentaire)
                    <p class="texte-carte">{{ $rv['motif'] }} {{ $justificatifRccm->commentaire }}</p>
                @endif
            @endif
            {{-- Aide au-dessus des deux champs : numéro et date d'immatriculation au même niveau (10/11/2026). --}}
            <p class="mt-4 texte-carte">{{ $rc['numero_aide'] }}</p>
            <div class="mt-2 grid gap-4 sm:grid-cols-2">
                <x-public.champ nom="rccm" :libelle="$rc['numero']" :valeur="$user->rccm" maxlength="60" autocomplete="off" autocapitalize="characters" :obligatoire="$titulaire === TypeTitulaire::Morale" />
                <x-public.champ nom="rccm_immatricule_le" type="date" :libelle="$rc['date']" :valeur="$user->rccm_immatricule_le?->toDateString()" max="{{ now()->toDateString() }}" :obligatoire="$titulaire === TypeTitulaire::Morale" />
                <div>@include('public.partials.champ-greffe', ['valeurId' => old('rccm_greffe_id', $user->rccm_greffe_id), 'valeurAutre' => old('rccm_greffe_autre', $user->rccm_greffe_autre), 'classeLibelle' => 'block font-semibold text-encre',
                    'obligatoire' => $titulaire === TypeTitulaire::Morale])</div>
            </div>
            <div class="mt-4">
                <label for="document_rccm" class="block font-semibold text-encre">{{ $justificatifRccm ? $rv['remplacer'] : $rc['document'] }}</label>
                <p id="document_rccm-aide" class="texte-carte">{{ $rv['document_aide'] }}</p>
                <input id="document_rccm" name="document" type="file" accept="image/jpeg,image/png,application/pdf" aria-describedby="document_rccm-aide"
                       class="mt-2 block w-full text-base file:mr-3 file:min-h-[44px] file:rounded-charte file:border-2 file:border-vert-fonce file:bg-white file:px-4 file:font-semibold file:text-vert-fonce">
                <x-public.erreur :messages="$errors->get('document')" />
            </div>
            <button type="submit" class="btn-secondaire mt-4">{{ $rv['enregistrer'] }}</button>
        </form>
        @endunless

        {{-- Groupe sanguin (05/11/2026) : facultatif, modifiable à tout moment ; imprimé sur la carte de commerçant seulement avec
             l'accord et un justificatif vérifié par l'ADMIN. --}}
        @php($gs = __('public.verification.groupe_sanguin'))
        @php($groupeChoisi = (string) old('groupe_sanguin', $user->groupe_sanguin_consenti_le ? $user->groupe_sanguin : ''))
        <form method="POST" action="{{ route('espace.verification.groupe-sanguin') }}" enctype="multipart/form-data" novalidate class="carte mt-6 p-5"
              id="groupe-sanguin" x-data="{ groupe: @js($groupeChoisi) }" data-groupe-sanguin>
            @csrf
            <h2 class="!text-lg">{{ $gs['titre'] }}</h2>
            <p class="texte-carte">{{ $gs['aide'] }}</p>
            @if ($justificatifGroupe)
                <p class="mt-3 font-semibold text-encre" data-statut-justificatif>{{ $gs['statuts'][$justificatifGroupe->statut->value] }}</p>
                @if ($justificatifGroupe->statut === StatutDocumentKyc::Rejete && $justificatifGroupe->commentaire)
                    <p class="texte-carte">{{ $gs['motif'] }} {{ $justificatifGroupe->commentaire }}</p>
                @endif
            @endif
            <div class="mt-4">
                <label for="groupe_sanguin" class="block font-semibold text-encre">{{ $gs['groupe'] }}</label>
                <select id="groupe_sanguin" name="groupe_sanguin" x-model="groupe" class="champ mt-2">
                    <option value="">{{ $gs['aucun'] }}</option>
                    @foreach (\App\Models\User::GROUPES_SANGUINS as $g)
                        <option value="{{ $g }}" @selected($groupeChoisi === $g)>{{ $g }}</option>
                    @endforeach
                </select>
                <x-public.erreur :messages="$errors->get('groupe_sanguin')" />
            </div>
            <div class="mt-4 space-y-4" x-show="groupe !== ''" @if ($groupeChoisi === '') x-cloak @endif>
                <x-public.case nom="consentement_groupe_sanguin" id="consentement_groupe_sanguin" value="1" :libelle="__('public.boutiques.reservation.groupe_sanguin_consentement')"
                               :checked="(bool) old('consentement_groupe_sanguin', $user->groupe_sanguin_consenti_le !== null)" />
                <x-public.erreur :messages="$errors->get('consentement_groupe_sanguin')" />
                <div>
                    <label for="justificatif" class="block font-semibold text-encre">{{ $justificatifGroupe ? $gs['remplacer'] : $gs['justificatif'] }}</label>
                    <p id="justificatif-aide" class="texte-carte">{{ $gs['justificatif_aide'] }}</p>
                    <input id="justificatif" name="justificatif" type="file" accept="image/jpeg,image/png,application/pdf" aria-describedby="justificatif-aide"
                           class="mt-2 block w-full text-base file:mr-3 file:min-h-[44px] file:rounded-charte file:border-2 file:border-vert-fonce file:bg-white file:px-4 file:font-semibold file:text-vert-fonce">
                    <x-public.erreur :messages="$errors->get('justificatif')" />
                </div>
            </div>
            <button type="submit" class="btn-secondaire mt-4">{{ $gs['enregistrer'] }}</button>
        </form>

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
