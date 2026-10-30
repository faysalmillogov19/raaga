{{--
    Aperçu de la carte d'identification (SUPER_ADMIN) : le gabarit des cartes générées (resources/views/cartes/_faces),
    recto et verso côte à côte, avec les valeurs du formulaire et un titulaire fictif marqué SPÉCIMEN. Rien n'est
    enregistré, aucun compte n'existe. Variables : $d (RenduCarte::specimen), $manquants.
--}}
@php($c = fn (string $cle, array $r = []) => __('admin.plateforme.carte.'.$cle, $r))

<x-admin.layout :titre="$c('apercu_titre')" :fil="[['libelle' => __('admin.menu.plateforme'), 'url' => route('admin.plateforme', ['section' => 'carte'])], ['libelle' => $c('apercu_titre')]]">
    <link rel="stylesheet" href="{{ asset_versionne('css/carte-raaga.css') }}">

    <x-admin.message type="info">{{ $c('apercu_bandeau') }}</x-admin.message>

    <div @class(['carte-id-etat mb-5', 'carte-id-etat-pret' => $manquants === [], 'carte-id-etat-manquant' => $manquants !== []]) role="status">
        <p class="font-bold">{{ $manquants === [] ? $c('pret') : $c('manquants') }}</p>
        @if ($manquants !== [])
            <p class="mt-1">{{ $c('manquants_detail') }} {{ collect($manquants)->map(fn ($m) => __('admin.plateforme.champs.'.$m))->join(', ') }}.</p>
        @endif
    </div>

    @if (($filigranesManquants ?? []) !== [])
        <x-admin.message type="avertissement" class="mb-5" data-filigranes-manquants>
            {{ $c('filigranes_avertissement') }} {{ collect($filigranesManquants)->map(fn ($m) => $c('filigrane_non_renseigne', ['champ' => __('admin.plateforme.champs.'.$m)]))->join(' ; ') }}.
        </x-admin.message>
    @endif

    <div class="cr-apercu-faces">
        @include('cartes._faces', ['d' => $d])
    </div>
</x-admin.layout>
