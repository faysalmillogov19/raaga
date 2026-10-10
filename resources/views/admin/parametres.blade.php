<x-admin.layout :titre="__('admin.menu.parametres')" :fil="[['libelle' => __('admin.menu.parametres')]]">
    <div class="bo-carte !mt-0">
        <p class="texte-carte max-w-3xl">{{ __('admin.parametres.intro') }}</p>
        <div class="mt-4 grid gap-3 sm:grid-cols-2">
            @foreach (['tranches' => 'admin.tranches', 'dividendes' => 'admin.dividendes', 'simulations' => 'admin.simulations', 'referentiels' => 'admin.referentiels'] as $cle => $route)
                @php([$titre, $texte] = __('admin.parametres.liens.'.$cle))
                <a href="{{ route($route) }}" class="flex items-start gap-3 rounded-charte border border-ligne p-4 no-underline hover:border-vert">
                    <x-admin.icone :nom="$cle" class="mt-0.5 h-5 w-5 text-vert-fonce" />
                    <span>
                        <span class="block font-bold text-vert-fonce">{{ $titre }}</span>
                        <span class="texte-carte block">{{ $texte }}</span>
                    </span>
                    <span class="bo-etiquette bo-etiquette-succes ml-auto shrink-0">{{ __('admin.section.lecture_seule') }}</span>
                </a>
            @endforeach
        </div>
    </div>
</x-admin.layout>
