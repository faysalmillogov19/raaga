{{--
    « Ma carte » du personnel : carte PERSONNEL émise par le SUPER_ADMIN (ou message tant qu'elle n'est pas émise), recto
    et verso du gabarit des cartes générées (« en cours de préparation » si un réglage de la carte manque), puis
    la photo de la carte, déposée ici et validée par l'ADMIN avant de figurer sur la carte.
--}}
@php
    $m = __('public.cartes.ma_carte');
    $p = __('admin.cartes.photos');
@endphp

<x-admin.layout :titre="$m['titre']">
    <div class="bo-carte !mt-0" data-ma-carte="personnel">
        @if ($carte === null)
            <p>{{ $m['aucune']['personnel'] }}</p>
        @else
            @if ($carte->statut !== \App\Enums\StatutCarte::Active)
                <x-admin.message type="avertissement">{{ $carte->statut->label() }} : {{ $m['inactive'] }}</x-admin.message>
            @endif
            @if ($faces === null)
                <x-admin.message type="info" data-carte-preparation>{{ __('carte.preparation') }}</x-admin.message>
            @elseif ($qr)
                <x-carte-actions :carte="$carte" :qr="$qr" :faces="$faces" />
            @else
                <x-carte-identification :faces="$faces" />
            @endif
        @endif
    </div>

    <section class="bo-carte" aria-labelledby="ma-photo-titre" data-ma-photo>
        <h2 id="ma-photo-titre" class="!text-[1.15rem]">{{ $p['ma_photo'] }}</h2>
        <p class="texte-carte mt-1 max-w-3xl">{{ $p['ma_photo_aide'] }}</p>
        @if ($photo)
            <p class="mt-3 font-semibold">{{ __('admin.cartes.photos.statut', ['statut' => $photo->statut->label()]) }}</p>
            @if ($photo->statut === \App\Enums\StatutDocumentKyc::Rejete && $photo->commentaire)
                <p class="texte-carte">{{ __('admin.cartes.photos.refusee', ['motif' => $photo->commentaire]) }}</p>
            @endif
        @endif
        <form method="POST" action="{{ route('admin.carte.photo') }}" enctype="multipart/form-data" class="mt-4 max-w-xl space-y-4">
            @csrf
            <x-admin.depot-fichier nom="photo" :libelle="$p['choisir']" formats=".jpg,.jpeg,.png" accept="image/jpeg,image/png" required />
            <x-admin.bouton type="submit" icone="importer">{{ $p['envoyer'] }}</x-admin.bouton>
        </form>
    </section>
</x-admin.layout>
