{{-- Vérification d'une carte Raaga sans connexion : « Carte valide » ou « Carte non valide », sans aucune donnée personnelle. --}}
@php($v = __('public.cartes.verification'))

<x-public.layout :titre="$v['titre']">
    <x-slot:head><meta name="robots" content="noindex, nofollow"></x-slot:head>

    <div class="conteneur py-8 tab:py-12">
        <section class="verif-carte {{ $valide ? 'verif-carte-valide' : 'verif-carte-invalide' }}" aria-labelledby="verif-resultat" data-verification="{{ $valide ? 'valide' : 'non_valide' }}">
            <x-icon :name="$valide ? 'check' : 'erreur'" class="verif-carte-icone" />
            <h1 id="verif-resultat" class="verif-carte-resultat">{{ $valide ? $v['valide'] : $v['non_valide'] }}</h1>
            <p class="verif-carte-aide">{{ $valide ? $v['aide_valide'] : $v['aide_non_valide'] }}</p>
        </section>
        <p class="verif-carte-note">{{ $v['note'] }}</p>
    </div>
</x-public.layout>
