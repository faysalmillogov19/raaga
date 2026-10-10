{{-- Page affichée par le middleware ModeMaintenancePublic (le back-office reste accessible). --}}
<x-public.layout :titre="__('public.maintenance.titre')">
    @include('public.partials.maintenance-contenu')
</x-public.layout>
