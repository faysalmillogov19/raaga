{{-- Trouver un guichet : guichets agréés actifs, filtrables par région puis par commune. --}}
<x-public.layout :titre="__('public.guichets.titre')">
    <x-public.page-entete :titre="__('public.guichets.titre')" :intro="__('public.guichets.intro')" :fil="[['libelle' => __('public.guichets.titre')]]" />

    <div class="conteneur py-8 tab:py-12">
        @if ($aucunGuichet)
            <x-public.alerte variante="info" :titre="__('public.guichets.vide_titre')">
                {{ __('public.guichets.vide') }}
                <a href="{{ route('contact') }}" class="font-semibold">{{ __('public.guichets.vide_contact') }}</a>
            </x-public.alerte>
        @else
            <form method="GET" action="{{ route('guichets') }}" x-data class="carte grid gap-4 p-5 tab:grid-cols-[1fr_1fr_auto] tab:items-end" aria-label="{{ __('public.guichets.filtrer') }}">
                <div>
                    <label for="region" class="block font-semibold text-encre">{{ __('public.guichets.region') }}</label>
                    <select id="region" name="region" class="champ mt-1.5" @change="$el.form.commune.value = ''; $el.form.requestSubmit()">
                        <option value="">{{ __('public.guichets.toutes_regions') }}</option>
                        @foreach ($regions as $r)
                            <option value="{{ $r }}" @selected($region === $r)>{{ $r }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="commune" class="block font-semibold text-encre">{{ __('public.guichets.commune') }}</label>
                    <select id="commune" name="commune" class="champ mt-1.5" @disabled(! $region) @change="$el.form.requestSubmit()"
                            @if (! $region) aria-describedby="commune-aide" @endif>
                        <option value="">{{ __('public.guichets.toutes_communes') }}</option>
                        @foreach ($communes as $c)
                            <option value="{{ $c }}" @selected($commune === $c)>{{ $c }}</option>
                        @endforeach
                    </select>
                    @if (! $region)
                        <p id="commune-aide" class="mt-1 texte-carte">{{ __('public.guichets.choisir_region') }}</p>
                    @endif
                </div>
                <button type="submit" class="btn-secondaire">{{ __('public.guichets.filtrer') }}</button>
            </form>

            <p class="mt-6 font-semibold text-encre" role="status">{{ trans_choice('public.guichets.resultats', $guichets->count(), ['n' => $guichets->count()]) }}</p>

            @if ($guichets->isEmpty())
                <x-public.alerte variante="info" class="mt-4">{{ __('public.guichets.aucun_resultat') }}</x-public.alerte>
            @else
                <ul class="mt-4 grid gap-4 tab:grid-cols-2 lg:grid-cols-3">
                    @foreach ($guichets as $guichet)
                        <li class="carte p-5">
                            <h2 class="!text-lg">{{ $guichet->nom }}</h2>
                            <p class="texte-carte">{{ $guichet->commune }} · {{ $guichet->region }}</p>
                            <dl class="mt-3 space-y-2 text-[0.95rem]">
                                <div class="flex items-start gap-2">
                                    <dt><x-public.icone nom="lieu" class="mt-0.5 h-5 w-5 text-gris" /><span class="sr-only">{{ __('public.guichets.adresse') }}</span></dt>
                                    <dd class="whitespace-pre-line">{{ $guichet->adresse }}</dd>
                                </div>
                                @if ($guichet->horaires)
                                    <div class="flex items-start gap-2">
                                        <dt><x-public.icone nom="horloge" class="mt-0.5 h-5 w-5 text-gris" /><span class="sr-only">{{ __('public.guichets.horaires') }}</span></dt>
                                        <dd>{{ $guichet->horaires }}</dd>
                                    </div>
                                @endif
                                @if ($guichet->telephone)
                                    <div class="flex items-start gap-2">
                                        <dt><x-public.icone nom="telephone" class="mt-0.5 h-5 w-5 text-gris" /><span class="sr-only">{{ __('public.guichets.telephone') }}</span></dt>
                                        <dd><a href="tel:{{ $guichet->telephone }}" class="inline-flex min-h-[44px] items-center font-semibold">{{ \App\Support\Telephone::formaterLocal($guichet->telephone) }}</a></dd>
                                    </div>
                                @endif
                            </dl>
                        </li>
                    @endforeach
                </ul>
            @endif
        @endif

        @if ($antiFraude = plateforme('messages.messageAntiFraude'))
            <x-public.alerte variante="attention" class="mt-8">{{ $antiFraude }}</x-public.alerte>
        @endif
    </div>
</x-public.layout>
