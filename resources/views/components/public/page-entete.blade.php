@props(['titre', 'intro' => null, 'fil' => []])

{{-- En-tête des pages internes : fil d'Ariane, titre, introduction. $fil = [['libelle' => …, 'url' => …|null], …] --}}
<div class="border-b border-ligne bg-fond">
    <div class="conteneur py-6 tab:py-9">
        <nav aria-label="{{ __('public.fil.libelle') }}">
            <ol class="flex flex-wrap items-center gap-1 text-sm text-gris">
                <li><a href="{{ route('accueil') }}" class="text-vert-fonce underline underline-offset-2">{{ __('public.menu.accueil') }}</a></li>
                @foreach ($fil as $element)
                    <li class="flex items-center gap-1">
                        <span aria-hidden="true">›</span>
                        @if (! empty($element['url']) && ! $loop->last)
                            <a href="{{ $element['url'] }}" class="text-vert-fonce underline underline-offset-2">{{ $element['libelle'] }}</a>
                        @else
                            <span @if ($loop->last) aria-current="page" @endif>{{ $element['libelle'] }}</span>
                        @endif
                    </li>
                @endforeach
            </ol>
        </nav>
        <h1 class="mt-3">{{ $titre }}</h1>
        @if ($intro)
            <p class="mt-3 max-w-3xl text-lg text-gris">{{ $intro }}</p>
        @endif
    </div>
</div>
