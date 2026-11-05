{{--
    Vérification d'un récépissé de préinscription Raaga (QR code), sans connexion : numéro, boutique, site, dates et état
    de la formalisation. Comme pour les cartes, aucune donnée personnelle (ni nom ni numéro de pièce).
--}}
@php($v = __('public.formalisation.verification'))

<x-public.layout :titre="$v['titre']">
    <x-slot:head><meta name="robots" content="noindex, nofollow"></x-slot:head>

    <div class="conteneur py-8 tab:py-12">
        <section class="verif-carte verif-carte-valide" aria-labelledby="verif-resultat" data-verification-recepisse="{{ $etat }}">
            <x-icon name="check" class="verif-carte-icone" />
            <h1 id="verif-resultat" class="verif-carte-resultat">{{ $v['authentique'] }}</h1>
            <p class="verif-carte-aide">{{ $v['etats'][$etat] }}</p>
        </section>
        <dl class="carte mx-auto mt-6 max-w-xl p-5" data-recepisse="{{ $recepisse->numero }}">
            <div class="flex justify-between gap-3"><dt class="texte-carte">{{ $v['numero'] }}</dt><dd class="font-bold text-encre">{{ $recepisse->numero }}</dd></div>
            <div class="mt-2 flex justify-between gap-3"><dt class="texte-carte">{{ $v['boutique'] }}</dt><dd class="font-semibold text-encre">{{ $recepisse->boutique?->code }}</dd></div>
            <div class="mt-2 flex justify-between gap-3"><dt class="texte-carte">{{ $v['site'] }}</dt><dd class="font-semibold text-encre">{{ $recepisse->boutique?->site?->nom }}</dd></div>
            <div class="mt-2 flex justify-between gap-3"><dt class="texte-carte">{{ $v['emis_le'] }}</dt><dd class="font-semibold text-encre">{{ $recepisse->emis_le->format('d/m/Y') }}</dd></div>
            <div class="mt-2 flex justify-between gap-3"><dt class="texte-carte">{{ $v['echeance'] }}</dt><dd class="font-semibold text-encre">{{ $recepisse->echeance_le->format('d/m/Y') }}</dd></div>
        </dl>
        <p class="verif-carte-note">{{ $v['note'] }}</p>
    </div>
</x-public.layout>
