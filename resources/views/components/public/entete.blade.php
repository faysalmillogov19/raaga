<header class="border-b border-ligne bg-white" x-data="{ menuOuvert: false }" @keydown.escape="menuOuvert = false">
    <div class="conteneur flex items-center gap-2 py-3 tab:gap-5 tab:py-4">
        {{-- Armoiries seules (ni texte ni logo dans l'en-tête) : lien vers l'accueil. --}}
        <div class="min-w-0 flex-1">
            <a href="{{ route('accueil') }}" class="inline-flex min-h-[44px] items-center"
               aria-label="{{ __('public.entete.accueil', ['nom' => plateforme('identite.nomPlateforme')]) }}">
                <x-public.armoiries class="h-11 w-auto tab:h-14" />
            </a>
        </div>

        <div class="hidden shrink-0 items-center gap-5 tab:flex">
            @if ($numeroVert = plateforme('contacts.numeroVert'))
                <a href="tel:{{ $numeroVert }}" class="hidden min-h-[44px] items-center gap-1.5 text-encre no-underline hover:underline lg:inline-flex"
                   title="{{ __('public.communs.numero_vert') }}">
                    <x-public.icone nom="telephone" class="h-5 w-5 text-vert-fonce" />
                    <span class="sr-only">{{ __('public.communs.numero_vert') }} :</span>
                    <span class="font-bold">{{ \App\Support\Telephone::formaterLocal($numeroVert) }}</span>
                </a>
            @endif
            <a href="{{ route('faq') }}" class="inline-flex min-h-[44px] items-center gap-1.5 font-semibold text-vert-fonce no-underline hover:underline">
                <x-public.icone nom="aide" />
                {{ __('public.entete.aide') }}
            </a>
            @auth
                {{-- Connecté : prénom + menu « Mon espace / Se déconnecter ». --}}
                <div class="relative" x-data="{ ouvert: false }" @click.outside="ouvert = false" @keydown.escape.window="ouvert = false">
                    <button type="button" class="btn-secondaire" @click="ouvert = ! ouvert"
                            :aria-expanded="ouvert.toString()" aria-expanded="false" aria-controls="menu-compte">
                        <x-public.icone nom="utilisateur" />
                        {{ Str::of(Auth::user()->prenoms ?: Auth::user()->name)->before(' ') }}
                        <x-public.icone nom="chevron" class="h-4 w-4 rotate-90" />
                    </button>
                    <ul id="menu-compte" x-show="ouvert" x-cloak aria-label="{{ __('public.entete.menu_compte') }}"
                        class="absolute right-0 z-20 mt-2 min-w-[200px] rounded-charte border border-ligne bg-white py-1 shadow-legere">
                        <li><a href="{{ route('espace') }}" class="flex min-h-[44px] items-center px-4 font-semibold text-encre no-underline hover:bg-fond">{{ __('public.entete.mon_espace') }}</a></li>
                        @if ($profil = Auth::user()->estBackoffice() ? route('admin.profil') : (Auth::user()->isCitoyen() ? route('espace.profil') : null))
                            <li><a href="{{ $profil }}"
                                   class="flex min-h-[44px] items-center px-4 font-semibold text-encre no-underline hover:bg-fond">{{ __('public.entete.mon_profil') }}</a></li>
                        @endif
                        <li>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="flex min-h-[44px] w-full items-center px-4 text-left font-semibold text-encre hover:bg-fond">{{ __('public.entete.se_deconnecter') }}</button>
                            </form>
                        </li>
                    </ul>
                </div>
            @else
                <a href="{{ route('connexion') }}" class="btn-secondaire">
                    <x-public.icone nom="utilisateur" />
                    {{ __('public.entete.mon_espace') }}
                </a>
            @endauth
        </div>

        <button type="button"
                class="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-charte border-2 border-vert text-vert-fonce tab:hidden"
                aria-controls="menu-principal"
                :aria-expanded="menuOuvert.toString()"
                aria-expanded="false"
                @click="menuOuvert = ! menuOuvert">
            <span class="sr-only" x-text="menuOuvert ? @js(__('public.entete.fermer_menu')) : @js(__('public.entete.ouvrir_menu'))">{{ __('public.entete.ouvrir_menu') }}</span>
            <x-public.icone nom="menu" class="h-6 w-6" x-show="! menuOuvert" />
            <x-public.icone nom="fermer" class="h-6 w-6" x-show="menuOuvert" x-cloak />
        </button>
    </div>

    <x-public.menu />
</header>
