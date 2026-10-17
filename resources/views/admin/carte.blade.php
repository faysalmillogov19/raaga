{{-- « Ma carte » du personnel : carte PERSONNEL émise par le SUPER_ADMIN, ou message tant qu'elle n'est pas émise. --}}
@php($m = __('public.cartes.ma_carte'))

<x-admin.layout :titre="$m['titre']">
    <div class="bo-carte !mt-0" data-ma-carte="personnel">
        @if ($carte === null)
            <p>{{ $m['aucune']['personnel'] }}</p>
        @else
            @if ($carte->statut !== \App\Enums\StatutCarte::Active)
                <x-admin.message type="avertissement">{{ $carte->statut->label() }} : {{ $m['inactive'] }}</x-admin.message>
            @endif
            @if ($qr)
                <x-carte-actions :carte="$carte" :qr="$qr" />
            @else
                <x-carte-raaga :carte="$carte" />
            @endif
        @endif
    </div>
</x-admin.layout>
