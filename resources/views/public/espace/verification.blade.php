{{--
    Vérification d'identité : statut en tête (toujours écrit), pièces déposées, formulaire de dépôt.
    Premier dépôt : choix CNIB (recto + verso) ou passeport (recto), + selfie. Après un rejet : seules les pièces rejetées.
--}}
@php
    use App\Enums\StatutDocumentKyc;
    use App\Enums\StatutKyc;

    $kyc = $user->statut_kyc ?? StatutKyc::NonSoumis;
    $premierDepot = $kyc === StatutKyc::NonSoumis;
    $titre = __('public.preparation.pages.verification.titre');
    $badgeKyc = ['NON_SOUMIS' => 'bg-fond text-encre', 'EN_ATTENTE' => 'bg-jaune-pale text-encre', 'VALIDE' => 'bg-vert-pale text-vert-fonce', 'REJETE' => 'bg-rouge-pale text-rouge'];
    $badgePiece = ['EN_ATTENTE' => 'bg-jaune-pale text-encre', 'VALIDE' => 'bg-vert-pale text-vert-fonce', 'REJETE' => 'bg-rouge-pale text-rouge'];
    $options = ['maxOctets' => 5 * 1024 * 1024, 'messages' => ['format' => __('public.verification.erreurs.fichiers.mimes'), 'taille' => __('public.verification.erreurs.fichiers.max')]];
@endphp

<x-public.layout :titre="$titre">
    <x-public.page-entete :titre="$titre" :fil="[['libelle' => __('public.menu.espace'), 'url' => route('espace')], ['libelle' => $titre]]" />

    <div class="conteneur max-w-3xl py-8 tab:py-12">
        @if (session('succes'))
            <x-public.alerte variante="info" class="mb-6" role="status">{{ session('succes') }}</x-public.alerte>
        @endif

        {{-- Statut --}}
        <section class="carte p-5" aria-labelledby="statut-titre">
            <div class="flex flex-wrap items-center gap-3">
                <h2 id="statut-titre" class="!text-lg">{{ __('public.verification.titre_statut') }}</h2>
                <span class="rounded-full px-3 py-1 text-sm font-bold {{ $badgeKyc[$kyc->value] }}">{{ __('public.espace.kyc.'.$kyc->value) }}</span>
            </div>
            <p class="mt-2">{{ __('public.verification.statut.'.$kyc->value) }}</p>
            @if ($kyc === StatutKyc::Valide)
                @if (\App\Support\Phase::presentationActions())
                    <a href="{{ route('souscrire') }}" class="btn-secondaire mt-4">{{ __('public.espace.vides.souscrire') }}</a>
                @else
                    <a href="{{ route('boutiques') }}" class="btn-secondaire mt-4">{{ __('public.boutiques.candidater_boutique') }}</a>
                @endif
            @endif

            @if ($dossier->isNotEmpty())
                <ul class="mt-4 space-y-3 border-t border-ligne pt-4">
                    @foreach ($dossier as $cle => $document)
                        <li class="flex items-start gap-3">
                            <img src="{{ route('espace.verification.fichier', $document) }}" alt="" class="h-16 w-16 shrink-0 rounded-charte border border-ligne object-cover">
                            <div class="min-w-0">
                                <p class="font-semibold text-encre">{{ __('public.verification.pieces.'.$cle.'.'.($cle === 'selfie' ? 'SELFIE' : $typePiece->value)) }}</p>
                                <span class="mt-1 inline-block rounded-full px-3 py-0.5 text-sm font-bold {{ $badgePiece[$document->statut->value] }}">{{ __('public.verification.statuts_piece.'.$document->statut->value) }}</span>
                                @if ($document->statut === StatutDocumentKyc::Rejete && $document->commentaire)
                                    <p class="mt-1 text-[0.95rem] text-rouge">{{ __('public.verification.motif', ['motif' => $document->commentaire]) }}</p>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- Dépôt : premier dépôt complet, ou seulement les pièces rejetées --}}
        @if ($aFournir)
            <form method="POST" action="{{ route('espace.verification.store') }}" enctype="multipart/form-data" novalidate class="carte mt-6 p-5"
                  x-data="{ piece: @js(old('type_piece', $typePiece?->value ?? 'CNIB')) }">
                @csrf
                <h2 class="!text-lg">{{ $premierDepot ? __('public.verification.deposer_titre') : __('public.verification.renvoyer_titre') }}</h2>
                <p class="mt-1 texte-carte">{{ __('public.verification.consignes') }}</p>

                @if ($errors->any())
                    <x-public.alerte variante="danger" class="mt-4" role="alert">{{ __('public.verification.erreurs.resume') }}</x-public.alerte>
                @endif

                @if ($premierDepot)
                    <fieldset class="mt-5">
                        <legend class="font-semibold text-encre">{{ __('public.verification.type_piece') }}</legend>
                        <div class="mt-2 grid gap-3 sm:grid-cols-2">
                            @foreach (['CNIB', 'PASSEPORT', 'NIF'] as $type)
                                <label class="flex min-h-[56px] cursor-pointer items-center gap-3 rounded-charte border-2 p-3"
                                       :class="piece === '{{ $type }}' ? 'border-vert-fonce bg-vert-pale' : 'border-ligne'">
                                    <input type="radio" name="type_piece" value="{{ $type }}" x-model="piece" class="h-5 w-5 text-vert focus:ring-0">
                                    <span class="font-semibold text-encre">{{ __('public.verification.types.'.$type) }}</span>
                                </label>
                            @endforeach
                        </div>
                        <x-public.erreur :messages="$errors->get('type_piece')" />
                    </fieldset>
                @endif

                <div class="mt-5 space-y-5">
                    @foreach (['recto', 'verso', 'rccm', 'selfie'] as $cle)
                        @if ($premierDepot || in_array($cle, $aFournir, true))
                            <div x-data="pieceKyc(@js($options))" @if ($cle === 'verso' && $premierDepot) x-show="piece === 'CNIB'" @endif
                                 @if ($cle === 'rccm' && $premierDepot) x-show="piece === 'NIF'" @endif>
                                <label for="{{ $cle }}" class="block font-semibold text-encre">
                                    @if ($cle === 'selfie')
                                        {{ __('public.verification.pieces.selfie.SELFIE') }}
                                    @elseif ($cle === 'rccm')
                                        {{ __('public.verification.pieces.rccm.NIF') }}
                                    @elseif ($premierDepot)
                                        <span x-text="@js(__('public.verification.pieces.'.$cle))[piece] ?? @js(__('public.verification.pieces.'.$cle.'.CNIB'))">{{ __('public.verification.pieces.'.$cle.'.CNIB') }}</span>
                                    @else
                                        {{ __('public.verification.pieces.'.$cle.'.'.$typePiece->value) }}
                                    @endif
                                </label>
                                <p id="{{ $cle }}-aide" class="texte-carte">{{ __('public.verification.aides.'.$cle) }}</p>
                                <input id="{{ $cle }}" name="{{ $cle }}" type="file" capture="{{ $cle === 'selfie' ? 'user' : 'environment' }}"
                                       @if (in_array($cle, ['recto', 'rccm'], true)) :accept="piece === 'NIF' || @js($cle === 'rccm') ? 'image/jpeg,image/png,application/pdf' : 'image/jpeg,image/png'" accept="image/jpeg,image/png"
                                       @else accept="image/jpeg,image/png" @endif
                                       @change="choisir($event)" aria-describedby="{{ $cle }}-aide {{ $cle }}-erreur"
                                       @if ($cle === 'verso' && $premierDepot) :disabled="piece !== 'CNIB'" @endif
                                       @if ($cle === 'rccm' && $premierDepot) :disabled="piece !== 'NIF'" @endif
                                       @error($cle) aria-invalid="true" @enderror
                                       class="mt-2 block w-full text-base file:mr-3 file:min-h-[44px] file:rounded-charte file:border-2 file:border-vert-fonce file:bg-white file:px-4 file:font-semibold file:text-vert-fonce">
                                <p x-show="erreur" x-text="erreur" x-cloak class="mt-1.5 font-semibold text-rouge" role="alert"></p>
                                <x-public.erreur :messages="$errors->get($cle)" :id="$cle.'-erreur'" />
                                <img x-show="apercu" :src="apercu" x-cloak alt="{{ __('public.verification.apercu') }}" class="mt-3 max-h-48 rounded-charte border border-ligne">
                            </div>
                        @endif
                    @endforeach
                </div>

                <button type="submit" class="btn-primaire mt-6 w-full sm:w-auto">{{ __('public.verification.envoyer') }}</button>
            </form>
        @endif
    </div>
</x-public.layout>
