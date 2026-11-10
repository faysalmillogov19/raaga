{{--
    Récapitulatif d'une boutique (code, site, niveau, bloc ou zone, type et sa description, surface, observations, loyer
    mensuel, caution, avance, total à payer), nombre de personnes déjà inscrites, puis inscription : pièce d'identité
    (CNIB, CIB-AES ou passeport) d'une personne physique, ou IFU d'une personne morale, numéro, trois
    engagements ; la pièce n'est demandée qu'à la première inscription de la campagne. Boutique libre : elle est
    pré-réservée au nom du citoyen pour le délai de paiement de la caution ; sinon, il prend son rang sur la liste d'attente.
--}}
@php
    $b = __('public.boutiques');
    $r = $b['reservation'];
    $titre = __('public.boutiques.reservation.titre', ['code' => $boutique->code]);
    $a = $b['attente'];
    $libre = $boutique->statut->reservable();
    $inscriptible = \App\Services\Boutiques\ListesAttente::ouverteAuxInscriptions($boutique);
    $limiteAtteinte = $limites['restants'] !== null && ($limites['restants'][$boutique->type_boutique_id] ?? 0) === 0;
    // Retenue tout de suite : boutique libre et citoyen sous la limite de ce type ; sinon, liste d'attente.
    $immediate = $libre && ! $limiteAtteinte;
    // Thème « nuit » : page en fond sombre, récapitulatif en fiche blanche.
    $nuit = \App\Support\ThemePublic::nuit();
@endphp

<x-public.layout :titre="$titre" :sombre="true">
    <x-public.page-entete :titre="$titre" :fil="[['libelle' => $b['titre'], 'url' => route('boutiques', ['site' => $boutique->site_id, 'niveau' => $boutique->niveau, 'bloc' => $boutique->bloc])], ['libelle' => $boutique->code]]" :sombre="true" />

    <div class="conteneur max-w-3xl py-8 tab:py-12">
        @if ($errors->has('boutique'))
            <x-public.alerte variante="danger" class="mb-6" role="alert">{{ $errors->first('boutique') }}</x-public.alerte>
        @endif

        <p class="mb-4 font-semibold text-encre" data-limites>{{ $limites['resume'] }}</p>

        <section class="{{ $nuit ? 'nuit-fiche' : 'carte p-5' }}" aria-labelledby="recap-titre" data-recapitulatif>
            <h2 id="recap-titre" class="!text-lg">{{ $r['recapitulatif'] }}</h2>
            <dl class="mt-3 grid gap-x-6 gap-y-2 text-[0.95rem] tab:grid-cols-2">
                <div class="flex justify-between gap-3"><dt>{{ $r['boutique'] }}</dt><dd class="font-bold tracking-wide text-encre">{{ $boutique->code }}</dd></div>
                <div class="flex justify-between gap-3"><dt>{{ $b['plan']['detail']['site'] }}</dt><dd class="text-right font-semibold">{{ $detail['site'] }}</dd></div>
                <div class="flex justify-between gap-3"><dt>{{ $b['plan']['detail']['niveau'] }}</dt><dd class="text-right font-semibold">{{ $detail['niveau'] }}</dd></div>
                <div class="flex justify-between gap-3"><dt>{{ $b['plan']['detail']['bloc'] }}</dt><dd class="font-semibold">{{ $detail['bloc'] }}</dd></div>
                <div class="flex justify-between gap-3 tab:col-span-2" data-type-detail><dt>{{ $r['type'] }}</dt>
                    <dd class="text-right"><span class="plan-sigle">{{ $detail['type_code'] }}</span> <span class="font-semibold">{{ $detail['type'] }}</span>
                        @if ($detail['type_description'])<span class="texte-carte block">{{ $detail['type_description'] }}</span>@endif</dd></div>
                <div class="flex justify-between gap-3"><dt>{{ $r['surface'] }}</dt><dd class="font-semibold">{{ $detail['surface'] }}</dd></div>
                @if ($detail['observations'])
                    <div class="flex justify-between gap-3 tab:col-span-2" data-observations><dt>{{ $b['plan']['detail']['observations'] }}</dt><dd class="text-right">{{ $detail['observations'] }}</dd></div>
                @endif
                @if ($tarif)
                    <div class="flex justify-between gap-3"><dt>{{ $r['loyer'] }}</dt><dd class="font-semibold">{{ format_fcfa($tarif['loyer_mensuel']) }}</dd></div>
                    <div class="flex justify-between gap-3 border-t border-ligne pt-2 tab:col-span-2"><dt class="font-semibold text-encre">{{ $r['caution'] }}</dt>
                        <dd class="text-xl font-extrabold text-vert-fonce" data-caution>{{ format_fcfa($tarif['caution']) }}</dd></div>
                    @if ($tarif['avance'] > 0)
                        <div class="flex justify-between gap-3 tab:col-span-2"><dt>{{ $r['avance'] }}</dt><dd class="font-semibold" data-avance>{{ format_fcfa($tarif['avance']) }}</dd></div>
                    @endif
                    <div class="flex justify-between gap-3 border-t border-ligne pt-2 tab:col-span-2" data-total><dt class="font-semibold text-encre">{{ $b['plan']['detail']['total'] }}</dt>
                        <dd class="font-bold">{{ $detail['montants']['total'] }}</dd></div>
                @endif
            </dl>
            @if ($tarif)
                <p class="mt-2 text-sm text-gris">{{ $b['montants']['mention_caution'] }}</p>
                @if ($tarif['avance'] > 0)<p class="mt-1 text-sm text-gris">{{ $r['avance_plus_tard'] }}</p>@endif
            @endif
        </section>

        {{-- Liste d'attente : nombre de personnes déjà inscrites (attributaire compris), jamais leur identité. --}}
        <p class="mt-4 font-semibold text-encre" data-interesses="{{ $interesses }}">{{ trans_choice('public.boutiques.attente.interesses', $interesses, ['n' => format_nombre($interesses)]) }}</p>

        @if (! $ouverte)
            <x-public.alerte variante="info" class="mt-6">{{ $r['fermee'] }}</x-public.alerte>
        @elseif ($inscription)
            <x-public.alerte variante="info" class="mt-6" data-inscription="{{ $inscription->statut->value }}"
                             :titre="$inscription->reservation ? $a['deja_retenu'] : $a['deja_inscrit']">
                @if ($inscription->reservation)
                    <a href="{{ route('boutiques.suivi', $inscription->reservation->reference) }}" class="font-semibold">{{ $r['suivi_titre'] }}</a>
                @else
                    {{ __('public.boutiques.attente.rang', ['rang' => $inscription->rang()]) }}
                    <a href="{{ route('espace', ['onglet' => 'boutiques']) }}" class="font-semibold">{{ $a['voir_espace'] }}</a>
                @endif
            </x-public.alerte>
        @elseif (! $inscriptible)
            <x-public.alerte variante="attention" class="mt-6" data-indisponible>{{ $r['indisponible'] }}</x-public.alerte>
        @elseif ($limites['commercant_atteint'])
            {{-- Deux boutiques au plus par commerçant : aucune autre sélection possible, avant tout paiement. --}}
            <x-public.alerte variante="danger" class="mt-6" role="alert" data-limite-commercant>{{ __('public.boutiques.erreurs.limite_commercant') }}</x-public.alerte>
        @elseif (! $tarif)
            <x-public.alerte variante="attention" class="mt-6">{{ $r['sans_tarif'] }}</x-public.alerte>
        @elseif ($identiteADemander)
            {{-- Demande de vérification d'identité d'abord (10/11/2026) ; retour à cette boutique une fois le dossier déposé. --}}
            @php($vi = __('public.boutiques.identite_avant'))
            @php($aCorriger = in_array(auth()->user()->statut_kyc, [\App\Enums\StatutKyc::Rejete, \App\Enums\StatutKyc::AMettreAJour], true))
            <x-public.alerte variante="attention" class="mt-6" :titre="$vi['titre']" data-identite-a-demander>
                <p>{{ auth()->user()->piece_expire_le?->lt(today()) ? $vi['expiree'] : ($aCorriger ? $vi['corriger'] : $vi['texte']) }}</p>
                <p class="mt-3"><a href="{{ route('espace.verification', ['retour' => $boutique->code]) }}" class="btn-plein inline-flex" data-lien-verification>{{ $vi['bouton'] }}</a></p>
            </x-public.alerte>
        @else
            @if (! $immediate)
                <x-public.alerte variante="attention" class="mt-6" :data-limite-atteinte="$limiteAtteinte && $libre ? '' : null" data-liste-attente>
                    {{ $libre ? $a['limite'] : $a['occupee'] }}
                </x-public.alerte>
            @else
                <p class="mt-4 texte-carte" data-boutique-libre>{{ $a['libre'] }}</p>
            @endif
            <form method="POST" action="{{ route('boutiques.reserver', $boutique->code) }}" enctype="multipart/form-data" class="carte mt-6 p-5" novalidate
                  x-data="{ piece: @js(old('type_piece', $pieceFournie?->type_piece ?? $pieces[0]->value)) }" data-formulaire-reservation>
                @csrf
                @if ($pieceFournie)
                    {{-- Pièce déjà fournie dans la campagne : reprise pour cette inscription. --}}
                    <p class="font-semibold text-encre" data-piece-fournie>{{ __('public.boutiques.attente.piece_fournie', ['type' => $r['types'][$pieceFournie->type_piece] ?? $pieceFournie->type_piece]) }}</p>
                @elseif ($pieceCompte)
                    {{-- Pièce déjà transmise sur le compte (validée ou en cours de vérification) : reprise, rien à redéposer. --}}
                    <p class="font-semibold text-encre" data-piece-compte>{{ __($pieceCompte->statut === \App\Enums\StatutDocumentKyc::Valide ? 'public.boutiques.attente.piece_verifiee' : 'public.boutiques.attente.piece_en_verification',
                        ['type' => $r['types'][$pieceCompte->type->value] ?? $pieceCompte->type->value, 'numero' => $pieceCompte->numero]) }}</p>
                @else
                <fieldset>
                    <legend class="text-lg font-bold text-encre"><x-requis /> {{ $r['piece_titre'] }}</legend>
                    <p class="texte-carte">{{ $r['piece_aide'] }}</p>
                    <div class="mt-3 grid gap-3 sm:grid-cols-3">
                        @foreach ($pieces as $type)
                            <label class="nuit-choix flex min-h-[48px] cursor-pointer items-center gap-3 rounded-charte border-2 p-3" :class="piece === @js($type->value) ? 'border-vert-fonce bg-vert-pale' : 'border-ligne'">
                                <input type="radio" name="type_piece" value="{{ $type->value }}" x-model="piece" class="h-5 w-5 text-vert focus:ring-0">
                                <span class="font-semibold text-encre">{{ $r['types'][$type->value] }}</span>
                            </label>
                        @endforeach
                    </div>
                    <x-public.erreur :messages="$errors->get('type_piece')" />
                </fieldset>

                <x-public.champ nom="numero_piece" :libelle="$r['numero_piece']" class="mt-4" maxlength="40" autocomplete="off" required nip />

                <div class="mt-4">
                    <label for="piece" class="block font-semibold text-encre"><x-requis /> {{ $r['piece'] }}</label>
                    <input id="piece" name="piece" type="file" accept="image/jpeg,image/png,application/pdf" required aria-describedby="piece-erreur"
                           class="mt-2 block w-full text-base file:mr-3 file:min-h-[44px] file:rounded-charte file:border-2 file:border-vert-fonce file:bg-white file:px-4 file:font-semibold file:text-vert-fonce">
                    <x-public.erreur :messages="$errors->get('piece')" id="piece-erreur" />
                </div>
                @endif
                @if ($sexeLieuDemandes)
                    {{-- Sexe et lieu de naissance, tels qu'inscrits sur la pièce (carte de commerçant). --}}
                    <fieldset class="mt-4" data-sexe>
                        <legend class="font-semibold text-encre"><x-requis /> {{ __('public.verification.sexe') }}</legend>
                        <div class="mt-2 flex flex-wrap gap-3">
                            @foreach (\App\Models\User::SEXES as $sexe)
                                <label class="nuit-choix flex min-h-[48px] cursor-pointer items-center gap-3 rounded-charte border-2 border-ligne px-4">
                                    <input type="radio" name="sexe" value="{{ $sexe }}" @checked(old('sexe', auth()->user()->sexe) === $sexe) class="h-5 w-5 text-vert focus:ring-0">
                                    <span class="font-semibold text-encre">{{ __('public.verification.sexes.'.$sexe) }}</span>
                                </label>
                            @endforeach
                        </div>
                        <x-public.erreur :messages="$errors->get('sexe')" />
                    </fieldset>
                    <x-public.champ nom="lieu_naissance" :libelle="__('public.verification.lieu_naissance')" :valeur="auth()->user()->lieu_naissance" class="mt-4" maxlength="100" autocomplete="off" obligatoire />
                @endif

                {{-- IFU : plus demandé à la réservation (10/11/2026) ; une personne physique le dépose dans « Vérifier mon identité »
                     (question de formalisation, ou formulaire « Votre IFU ») ; sans IFU, récépissé de préinscription. --}}

                {{-- Activité et personne à prévenir déjà renseignées : rappelées, champs ouverts seulement sur « Modifier » (05/11/2026). --}}
                @php($moi = auth()->user())
                @php($modifier = ! $infosCommercantConnues || $errors->hasAny(['secteur_activite_id', 'secteur_activite_autre', 'personne_prevenir_nom', 'personne_prevenir_telephone', 'groupe_sanguin', 'consentement_groupe_sanguin']))
                <div x-data="{ modifier: @js($modifier) }">
                @if ($infosCommercantConnues)
                    <div class="mt-6 rounded-charte border-2 border-ligne p-4" x-show="! modifier" @if ($modifier) x-cloak @endif data-infos-connues>
                        <p class="font-bold text-encre">{{ $r['infos_connues_titre'] }}</p>
                        <p class="texte-carte">{{ $r['infos_connues_aide'] }}</p>
                        <ul class="mt-3 space-y-1 text-encre">
                            <li><span class="font-semibold">{{ $r['secteur'] }} :</span> {{ $secteurLibelle }}</li>
                            <li><span class="font-semibold">{{ $r['infos_prevenir'] }} :</span> {{ $moi->personne_prevenir_nom }}, {{ \App\Support\Telephone::formaterLocal($moi->personne_prevenir_telephone) }}</li>
                            <li><span class="font-semibold">{{ $r['infos_groupe_sanguin'] }} :</span> {{ $moi->groupe_sanguin_consenti_le && $moi->groupe_sanguin ? $moi->groupe_sanguin : $r['infos_non_renseigne'] }}</li>
                        </ul>
                        <button type="button" class="btn-secondaire mt-4" @click="modifier = true" data-modifier-infos>{{ $r['infos_modifier'] }}</button>
                    </div>
                @endif
                <div x-show="modifier" @unless ($modifier) x-cloak @endunless>
                {{-- Activité principale du commerçant (03/11/2026) ; « Autre activité » : précision libre. --}}
                @php($secteurChoisi = (string) old('secteur_activite_id', auth()->user()->secteur_activite_id))
                <div class="mt-6" x-data="{ secteur: @js($secteurChoisi) }" data-secteur-activite>
                    <label for="secteur_activite_id" class="block font-bold text-encre"><x-requis /> {{ $r['secteur'] }}</label>
                    <p id="secteur_activite_id-aide" class="texte-carte">{{ $r['secteur_aide'] }}</p>
                    <select id="secteur_activite_id" name="secteur_activite_id" x-model="secteur" required aria-describedby="secteur_activite_id-aide"
                            @error('secteur_activite_id') aria-invalid="true" @enderror class="champ mt-2 @error('secteur_activite_id') est-invalide @enderror">
                        <option value="">{{ $r['secteur_choisir'] }}</option>
                        @foreach ($secteurs as $famille => $liste)
                            <optgroup label="{{ $famille }}">
                                @foreach ($liste as $id => $libelle)
                                    <option value="{{ $id }}" @selected($secteurChoisi === (string) $id)>{{ $libelle }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    <x-public.erreur :messages="$errors->get('secteur_activite_id')" />
                    <div x-show="secteur === @js((string) $secteurAutre)" @if ($secteurChoisi !== (string) $secteurAutre) x-cloak @endif>
                        <x-public.champ nom="secteur_activite_autre" :libelle="$r['secteur_autre']" :valeur="auth()->user()->secteur_activite_autre"
                                        class="mt-3" maxlength="120" autocomplete="off" obligatoire />
                    </div>
                </div>

                {{-- Carte de commerçant : personne à prévenir ; groupe sanguin facultatif, imprimé seulement avec consentement. --}}
                <fieldset class="mt-6" x-data="{ groupe: @js((string) old('groupe_sanguin', $moi->groupe_sanguin_consenti_le ? $moi->groupe_sanguin : '')) }" data-carte-commercant>
                    <legend class="text-lg font-bold text-encre">{{ $r['carte_titre'] }}</legend>
                    <p class="texte-carte">{{ $r['carte_aide'] }}</p>
                    <div class="mt-3 grid gap-4 sm:grid-cols-2">
                        <x-public.champ nom="personne_prevenir_nom" :libelle="$r['prevenir_nom']" :valeur="$moi->personne_prevenir_nom" maxlength="150" autocomplete="off" obligatoire />
                        <x-public.champ nom="personne_prevenir_telephone" type="tel" :libelle="$r['prevenir_telephone']" :valeur="$moi->personne_prevenir_telephone ? \App\Support\Telephone::formaterLocal($moi->personne_prevenir_telephone) : null"
                                        maxlength="20" inputmode="tel" autocomplete="off" obligatoire />
                    </div>
                    <div class="mt-4">
                        <label for="groupe_sanguin" class="block font-semibold text-encre">{{ $r['groupe_sanguin'] }}</label>
                        <p id="groupe_sanguin-aide" class="texte-carte">{{ $r['groupe_sanguin_aide'] }}</p>
                        <select id="groupe_sanguin" name="groupe_sanguin" x-model="groupe" aria-describedby="groupe_sanguin-aide" class="champ mt-2">
                            <option value="">{{ $r['groupe_sanguin_aucun'] }}</option>
                            @php($groupeChoisi = (string) old('groupe_sanguin', $moi->groupe_sanguin_consenti_le ? $moi->groupe_sanguin : ''))
                            @foreach ($groupes as $g)
                                <option value="{{ $g }}" @selected($groupeChoisi === $g)>{{ $g }}</option>
                            @endforeach
                        </select>
                        <x-public.erreur :messages="$errors->get('groupe_sanguin')" />
                        <div class="mt-3" x-show="groupe !== ''" @if (! old('groupe_sanguin', $moi->groupe_sanguin_consenti_le ? $moi->groupe_sanguin : null)) x-cloak @endif>
                            <x-public.case nom="consentement_groupe_sanguin" id="consentement_groupe_sanguin" value="1" :libelle="$r['groupe_sanguin_consentement']"
                                           :checked="(bool) old('consentement_groupe_sanguin', $moi->groupe_sanguin_consenti_le !== null)" />
                            <x-public.erreur :messages="$errors->get('consentement_groupe_sanguin')" />
                            {{-- Justificatif : dans « Vérifier mon identité », modifiable à tout moment (05/11/2026). --}}
                            <p class="mt-2 texte-carte" data-renvoi-justificatif>{{ $r['justificatif_groupe_sanguin_renvoi'] }} <a href="{{ route('espace.verification') }}#groupe-sanguin" class="lien">{{ $r['justificatif_groupe_sanguin_lien'] }}</a></p>
                        </div>
                    </div>
                </fieldset>
                </div>
                </div>

                <fieldset class="mt-6">
                    <legend class="font-bold text-encre"><x-requis /> {{ $r['engagements'] }}</legend>
                    <p class="texte-carte">{{ $r['engagements_aide'] }}</p>
                    <div class="mt-2 space-y-3">
                        @foreach ($engagements as $document)
                            <x-public.case :nom="'engagements[]'" :id="'engagement-'.$document->value" :value="$document->value"
                                           :libelle="__('public.boutiques.engagements.'.$document->value, ['version' => $campagne->version])" />
                        @endforeach
                    </div>
                    <x-public.erreur :messages="$errors->get('engagements')" />
                </fieldset>

                @if ($immediate)
                    <p class="mt-5 font-semibold text-encre">{{ __('public.boutiques.reservation.delai', ['delai' => $campagne->libelleDelaiPaiement()]) }}</p>
                @endif
                <button type="submit" class="btn-primaire mt-4 w-full sm:w-auto">{{ $immediate ? $a['bouton_reserver'] : $a['bouton_inscrire'] }}</button>
            </form>
        @endif
    </div>
</x-public.layout>
