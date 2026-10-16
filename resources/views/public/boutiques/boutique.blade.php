{{--
    Récapitulatif d'une boutique (code, site, niveau, bloc ou zone, type et sa description, surface, observations, loyer
    mensuel, caution, avance, total à payer) et confirmation de la réservation :
    pièce d'identité (CNIB, passeport ou NIF ; RCCM facultatif), numéro, trois engagements. La boutique est alors
    pré-réservée au nom du citoyen pour le délai de paiement de la caution.
--}}
@php
    $b = __('public.boutiques');
    $r = $b['reservation'];
    $titre = __('public.boutiques.reservation.titre', ['code' => $boutique->code]);
    $disponible = $boutique->statut->reservable();
    $limiteAtteinte = $limites['restants'] !== null && ($limites['restants'][$boutique->type_boutique_id] ?? 0) === 0;
@endphp

<x-public.layout :titre="$titre">
    <x-public.page-entete :titre="$titre" :fil="[['libelle' => $b['titre'], 'url' => route('boutiques', ['site' => $boutique->site_id, 'niveau' => $boutique->niveau, 'bloc' => $boutique->bloc])], ['libelle' => $boutique->code]]" />

    <div class="conteneur max-w-3xl py-8 tab:py-12">
        @if ($errors->has('boutique'))
            <x-public.alerte variante="danger" class="mb-6" role="alert">{{ $errors->first('boutique') }}</x-public.alerte>
        @endif

        <p class="mb-4 font-semibold text-encre" data-limites>{{ $limites['resume'] }}</p>

        <section class="carte p-5" aria-labelledby="recap-titre" data-recapitulatif>
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

        @if (! $ouverte)
            <x-public.alerte variante="info" class="mt-6">{{ $r['fermee'] }}</x-public.alerte>
        @elseif (! $disponible)
            <x-public.alerte variante="attention" class="mt-6" data-indisponible>{{ $r['indisponible'] }}</x-public.alerte>
        @elseif ($limiteAtteinte)
            <x-public.alerte variante="attention" class="mt-6" data-limite-atteinte :titre="$b['limites']['atteinte']">{{ __('public.boutiques.limites.atteinte_detail', ['n' => $campagne->limitePour($boutique->type_boutique_id), 'type' => $boutique->type?->code ?? $boutique->type?->libelle, 'restants' => $limites['resume']]) }}</x-public.alerte>
        @elseif (! $tarif)
            <x-public.alerte variante="attention" class="mt-6">{{ $r['sans_tarif'] }}</x-public.alerte>
        @else
            <form method="POST" action="{{ route('boutiques.reserver', $boutique->code) }}" enctype="multipart/form-data" class="carte mt-6 p-5" novalidate
                  x-data="{ piece: @js(old('type_piece', 'CNIB')) }" data-formulaire-reservation>
                @csrf
                <fieldset>
                    <legend class="text-lg font-bold text-encre"><span class="champ-requis" aria-hidden="true">*</span> {{ $r['piece_titre'] }}</legend>
                    <p class="texte-carte">{{ $r['piece_aide'] }}</p>
                    <div class="mt-3 grid gap-3 sm:grid-cols-3">
                        @foreach ($pieces as $type)
                            <label class="flex min-h-[48px] cursor-pointer items-center gap-3 rounded-charte border-2 p-3" :class="piece === @js($type->value) ? 'border-vert-fonce bg-vert-pale' : 'border-ligne'">
                                <input type="radio" name="type_piece" value="{{ $type->value }}" x-model="piece" class="h-5 w-5 text-vert focus:ring-0">
                                <span class="font-semibold text-encre">{{ $r['types'][$type->value] }}</span>
                            </label>
                        @endforeach
                    </div>
                    <x-public.erreur :messages="$errors->get('type_piece')" />
                </fieldset>

                <x-public.champ nom="numero_piece" :libelle="$r['numero_piece']" class="mt-4" maxlength="40" autocomplete="off" required />

                <div class="mt-4">
                    <label for="piece" class="block font-semibold text-encre"><span class="champ-requis" aria-hidden="true">*</span> {{ $r['piece'] }}</label>
                    <input id="piece" name="piece" type="file" accept="image/jpeg,image/png,application/pdf" required aria-describedby="piece-erreur"
                           class="mt-2 block w-full text-base file:mr-3 file:min-h-[44px] file:rounded-charte file:border-2 file:border-vert-fonce file:bg-white file:px-4 file:font-semibold file:text-vert-fonce">
                    <x-public.erreur :messages="$errors->get('piece')" id="piece-erreur" />
                </div>
                <div class="mt-4" x-show="piece === 'NIF'" x-cloak>
                    <label for="rccm" class="block font-semibold text-encre">{{ $r['rccm'] }}</label>
                    <input id="rccm" name="rccm" type="file" accept="image/jpeg,image/png,application/pdf" :disabled="piece !== 'NIF'"
                           class="mt-2 block w-full text-base file:mr-3 file:min-h-[44px] file:rounded-charte file:border-2 file:border-vert-fonce file:bg-white file:px-4 file:font-semibold file:text-vert-fonce">
                    <x-public.erreur :messages="$errors->get('rccm')" />
                </div>

                <fieldset class="mt-6">
                    <legend class="font-bold text-encre"><span class="champ-requis" aria-hidden="true">*</span> {{ $r['engagements'] }}</legend>
                    <p class="texte-carte">{{ $r['engagements_aide'] }}</p>
                    <div class="mt-2 space-y-3">
                        @foreach ($engagements as $document)
                            <x-public.case :nom="'engagements[]'" :id="'engagement-'.$document->value" :value="$document->value"
                                           :libelle="__('public.boutiques.engagements.'.$document->value, ['version' => $campagne->version])" />
                        @endforeach
                    </div>
                    <x-public.erreur :messages="$errors->get('engagements')" />
                </fieldset>

                <p class="mt-5 font-semibold text-encre">{{ __('public.boutiques.reservation.delai', ['delai' => $campagne->libelleDelaiPaiement()]) }}</p>
                <button type="submit" class="btn-primaire mt-4 w-full sm:w-auto">{{ $r['confirmer'] }}</button>
            </form>
        @endif
    </div>
</x-public.layout>
