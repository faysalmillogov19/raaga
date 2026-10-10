<x-public.layout :titre="$actualite['titre']" :description="$actualite['resume']">
    <x-public.page-entete :titre="$actualite['titre']"
                          :fil="[['libelle' => __('public.actualite.titre_rubrique')], ['libelle' => $actualite['titre']]]" />

    <div class="conteneur grid grid-cols-[minmax(0,1fr)] gap-10 py-8 tab:py-12 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)] lg:gap-12">
        <article>
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                <span class="rounded-charte bg-vert-pale px-2 py-0.5 font-semibold text-vert-fonce">{{ $actualite['etiquette'] }}</span>
                <time datetime="{{ $actualite['date']->toDateString() }}" class="text-gris">{{ __('public.actualite.publie_le', ['date' => $actualite['date']->translatedFormat('j F Y')]) }}</time>
            </div>
            <div class="mt-5 max-w-prose space-y-4 text-lg leading-relaxed">
                @foreach ($actualite['contenu'] as $paragraphe)
                    <p>{{ $paragraphe }}</p>
                @endforeach
            </div>
        </article>

        @if ($autres)
            <aside aria-labelledby="autres-titre">
                <h2 id="autres-titre" class="!text-lg">{{ __('public.actualite.autres') }}</h2>
                <ul class="mt-3 space-y-3">
                    @foreach ($autres as $autre)
                        <li class="carte p-4">
                            <time datetime="{{ $autre['date']->toDateString() }}" class="text-sm text-gris">{{ $autre['date']->translatedFormat('j F Y') }}</time>
                            <a href="{{ route('actualites.show', $autre['slug']) }}" class="lien mt-1 block">{{ $autre['titre'] }}</a>
                        </li>
                    @endforeach
                </ul>
            </aside>
        @endif
    </div>
</x-public.layout>
