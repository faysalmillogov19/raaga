@props(['carte', 'qr'])

{{--
    Carte active du titulaire : la carte avec son QR code, l'affichage plein écran pour le scan (QR code en grand, sur
    fond blanc), le téléchargement en PDF (format carte bancaire) et en image PNG.
--}}
@php($a = __('public.cartes.actions'))
<div x-data="{ ouvrir() { this.$refs.plein.showModal(); this.$refs.plein.requestFullscreen?.().catch(() => {}) }, fermer() { document.fullscreenElement && document.exitFullscreen(); this.$refs.plein.close() } }">
    <x-carte-raaga :carte="$carte">
        <x-slot:qr>{!! $qr !!}</x-slot:qr>
    </x-carte-raaga>

    <div class="carte-raaga-actions">
        <button type="button" class="btn-primaire" @click="ouvrir()" data-plein-ecran>
            <x-icon name="voir" />{{ $a['plein_ecran'] }}
        </button>
        <a href="{{ route('cartes.fichier', [$carte, 'pdf']) }}" class="btn-secondaire" data-telecharger="pdf">
            <x-icon name="exporter" />{{ $a['pdf'] }}
        </a>
        <a href="{{ route('cartes.fichier', [$carte, 'png']) }}" class="btn-secondaire" data-telecharger="png">
            <x-icon name="exporter" />{{ $a['png'] }}
        </a>
    </div>

    {{-- Plein écran : QR code en grand pour le scan, nom et numéro de carte. --}}
    <dialog x-ref="plein" class="carte-plein-ecran" aria-labelledby="carte-plein-titre" @close="document.fullscreenElement && document.exitFullscreen()">
        <div class="carte-plein-ecran-corps">
            <p id="carte-plein-titre" class="carte-plein-ecran-nom">{{ $carte->contenu['nom'] ?? '' }}</p>
            <p class="carte-plein-ecran-type">{{ $carte->type->label() }} · {{ $carte->numero }}</p>
            <div class="carte-plein-ecran-qr">{!! $qr !!}</div>
            @if ($carte->valide_jusqu_au)
                <p class="carte-plein-ecran-type">{{ __('public.cartes.carte.valide_jusqu_au') }} {{ $carte->valide_jusqu_au->format('d/m/Y') }}</p>
            @endif
            <button type="button" class="btn-secondaire" @click="fermer()" autofocus>{{ $a['fermer'] }}</button>
        </div>
    </dialog>
</div>
