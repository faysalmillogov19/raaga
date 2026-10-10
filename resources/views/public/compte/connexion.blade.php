<x-public.carte-compte :titre="__('public.compte.connexion.titre')" :intro="__('public.compte.connexion.intro')">

    @if ($etape === 'telephone')
        <x-public.compte.etape-telephone :action="route('connexion.telephone')">
            @if (session('compte.inconnu'))
                <p class="alerte-compte">
                    {{ __('public.compte.connexion.inconnu') }}
                    <a href="{{ route('inscription') }}">{{ __('public.compte.connexion.creer') }}</a>
                </p>
            @endif
        </x-public.compte.etape-telephone>
    @else
        <x-public.compte.etape-code :action="route('connexion.code')" :renvoyer="route('connexion.renvoyer')"
                                    :telephone="$telephone" :secondes="$secondesRenvoi"
                                    :bouton="__('public.compte.connexion.bouton')">
            <a href="{{ route('connexion.modifier') }}" class="lien-action">{{ __('public.compte.modifier_numero') }}</a>
        </x-public.compte.etape-code>
    @endif

    <x-slot:pied>
        {{ __('public.compte.connexion.pas_de_compte') }} <a href="{{ route('inscription') }}">{{ __('public.compte.connexion.creer') }}</a>
    </x-slot:pied>
</x-public.carte-compte>
