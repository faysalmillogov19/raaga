@props(['demarche'])

{{-- Badge de démarche : « Investir » (accent or, pièce avec une pousse) ou « Boutiques » (accent vert, boutique avec auvent). --}}
<span {{ $attributes->merge(['class' => 'badge-demarche demarche-'.$demarche]) }} data-badge-demarche="{{ $demarche }}">
    <x-icon :name="\App\Support\Demarche::PICTOS[$demarche]" class="h-4 w-4" data-picto="{{ \App\Support\Demarche::PICTOS[$demarche] }}" />
    {{ __('public.demarches.badges.'.$demarche) }}
</span>
