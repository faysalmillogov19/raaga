{{-- Maintenance (php artisan down ou HTTP 503) : message et date de retour des paramètres de la plateforme. --}}
@php
    $maintenance = rescue(fn () => plateforme('messages.modeMaintenance'), [], false) ?: [];
    $finPrevue = rescue(fn () => ! empty($maintenance['finPrevue']) ? \Carbon\Carbon::parse($maintenance['finPrevue']) : null, null, false);
@endphp

@extends('errors.cadre', [
    'code' => 503,
    'titre' => __('public.maintenance.titre'),
    'message' => ($maintenance['message'] ?? null) ?: __('public.erreurs.503.message'),
])

@section('complement')
    @if ($finPrevue)
        <p class="mt-3 font-semibold text-encre">{{ __('public.maintenance.fin', ['date' => $finPrevue->translatedFormat('j F Y \à H\hi')]) }}</p>
    @endif
    <p class="mt-3 text-gris">{{ __('public.maintenance.merci') }}</p>
@endsection
