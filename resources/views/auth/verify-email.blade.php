<x-guest-layout :titre="__('public.auth.verification.titre')" :intro="__('public.auth.verification.intro')">
    @if (session('status') == 'verification-link-sent')
        <x-public.alerte variante="info" class="mb-6" role="status">{{ __('public.auth.verification.envoye') }}</x-public.alerte>
    @endif

    <p class="leading-relaxed">{{ __('public.auth.verification.texte') }}</p>

    <div class="mt-6 flex flex-col gap-3 sm:flex-row">
        <form method="POST" action="{{ route('verification.send') }}" class="flex-1">
            @csrf
            <button type="submit" class="btn-primaire w-full">{{ __('public.auth.verification.renvoyer') }}</button>
        </form>

        <form method="POST" action="{{ route('logout') }}" class="flex-1">
            @csrf
            <button type="submit" class="btn-secondaire w-full">{{ __('public.auth.verification.deconnexion') }}</button>
        </form>
    </div>
</x-guest-layout>
