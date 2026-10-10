<x-institutionnel.layout :titre="__('institutionnel.menu.projet')" :fil="[['libelle' => __('institutionnel.menu.projet')]]">
    <div class="grid gap-[18px] xl:grid-cols-2">
        <section class="bo-carte !mt-0" aria-labelledby="chantier-titre">
            <h2 id="chantier-titre" class="!text-[1.15rem]">{{ __('public.transparence.chantier') }}</h2>
            @if ($chantier)
                <x-public.frise class="mt-5" :etapes="array_map(fn ($e) => ['titre' => $e['titre'], 'detail' => $e['periode'], 'statut' => $e['statut']], $chantier)" />
            @else
                <p class="texte-carte mt-2">{{ __('public.transparence.chantier_vide') }}</p>
            @endif
        </section>

        <section class="bo-carte !mt-0" aria-labelledby="rapports-titre">
            <h2 id="rapports-titre" class="!text-[1.15rem]">{{ __('institutionnel.projet.rapports') }}</h2>
            @if ($rapports)
                <ul class="mt-3 divide-y divide-ligne">
                    @foreach ($rapports as $rapport)
                        <li class="py-2">
                            @if ($rapport['url'])
                                <a href="{{ $rapport['url'] }}" class="font-bold text-vert-fonce underline underline-offset-2" download>{{ $rapport['titre'] }}</a>
                            @else
                                <span class="font-semibold text-encre">{{ $rapport['titre'] }}</span>
                            @endif
                            <span class="texte-carte block">{{ \Carbon\CarbonImmutable::parse($rapport['date'])->translatedFormat('j F Y') }}</span>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="texte-carte mt-2">{{ __('public.transparence.rapports_vide') }}</p>
            @endif
            <p class="texte-carte mt-4">{{ __('institutionnel.projet.apres_ouverture') }}</p>
        </section>
    </div>
</x-institutionnel.layout>
