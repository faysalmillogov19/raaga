{{--
    « Ma carte » du citoyen : un onglet par type (commerçant, actionnaire) ; carte active, suspendue, ou « En attente de
    vérification d'identité » tant que le dossier n'est pas conforme. Carte : recto et verso du gabarit des cartes générées ;
    carte active : plein écran pour le scan, téléchargement en PDF et en image PNG. Réglage de la carte manquant : « en
    cours de préparation ».
--}}
@php
    $m = __('public.cartes.ma_carte');
    $titre = $m['titre'];
@endphp
<x-public.layout :titre="$titre">
    <x-public.page-entete :titre="$titre" :fil="[['libelle' => __('public.menu.espace'), 'url' => route('espace')], ['libelle' => $titre]]" />

    <div class="conteneur max-w-3xl py-8 tab:py-12">
        <nav class="flex flex-wrap gap-2" aria-label="{{ $m['types'] }}">
            @foreach ($cartes as $cle => $c)
                <a href="{{ route('espace.carte', ['type' => $cle]) }}" class="{{ $cle === $choisi ? 'btn-primaire' : 'btn-secondaire' }}" @if ($cle === $choisi) aria-current="page" @endif>
                    {{ \App\Enums\TypeCarte::from(strtoupper($cle))->label() }}
                </a>
            @endforeach
        </nav>

        <section class="mt-6" data-ma-carte="{{ $choisi }}">
            @if ($carte === null)
                <div class="carte p-5"><p>{{ $m['aucune'][$choisi] }}</p></div>
            @elseif ($carte->statut === \App\Enums\StatutCarte::EnAttente)
                <x-public.alerte variante="info" :titre="$m['attente_titre']" data-carte-en-attente>{{ $m['attente'] }}</x-public.alerte>
            @else
                @if ($carte->statut !== \App\Enums\StatutCarte::Active)
                    <x-public.alerte variante="attention" class="mb-4" :titre="$carte->statut->label()" data-carte-inactive>{{ $m['inactive'] }}</x-public.alerte>
                @endif
                @if ($qr && $carte->fichiersEnEchec())
                    <x-public.alerte variante="attention" class="mb-4" data-carte-echec>{{ __('carte.echec') }}</x-public.alerte>
                @endif
                @if ($faces === null)
                    <x-public.alerte variante="info" data-carte-preparation>{{ __('carte.preparation') }}</x-public.alerte>
                @elseif ($qr)
                    <x-carte-actions :carte="$carte" :qr="$qr" :faces="$faces" />
                @else
                    <x-carte-identification :faces="$faces" />
                @endif
            @endif
        </section>
    </div>
</x-public.layout>
