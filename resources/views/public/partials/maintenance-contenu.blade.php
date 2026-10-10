{{-- Contenu de la page de maintenance. Variables : $message, $finPrevue (Carbon|null). --}}
<div class="conteneur max-w-2xl py-16 text-center">
    <x-public.armoiries class="mx-auto h-20 w-auto" />
    <h1 class="mt-6">{{ __('public.maintenance.titre') }}</h1>
    @if ($message)
        <p class="mt-4 text-lg leading-relaxed">{{ $message }}</p>
    @endif
    @if ($finPrevue)
        <p class="mt-3 font-semibold text-encre">{{ __('public.maintenance.fin', ['date' => $finPrevue->translatedFormat('j F Y \à H\hi')]) }}</p>
    @endif
    <p class="mt-3 text-gris">{{ __('public.maintenance.merci') }}</p>
    @if ($numeroVert = plateforme('contacts.numeroVert'))
        <p class="mt-6">{{ __('public.communs.numero_vert') }} : <strong>{{ \App\Support\Telephone::formaterLocal($numeroVert) }}</strong></p>
    @endif
</div>
