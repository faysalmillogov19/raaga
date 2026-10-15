{{-- Mon espace, onglet « Mes actions » : dividendes et compte de versement. --}}
                    <x-etat-vide icone="dividendes" :titre="__('public.espace.vides.dividendes_titre')">
                        {{ __('public.espace.vides.dividendes') }}
                    </x-etat-vide>
                    <div class="carte mx-auto mt-4 max-w-xl p-5">
                        <h3 class="!text-base">{{ __('public.espace.compte_versement') }}</h3>
                        <p class="mt-1 texte-carte">{{ __('public.espace.compte_versement_vide') }}</p>
                    </div>
