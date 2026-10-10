<x-public.layout :titre="__('public.faq.titre')">
    <x-public.page-entete :titre="__('public.faq.titre')" :intro="__('public.faq.intro')"
                          :fil="[['libelle' => __('public.faq.titre')]]" />

    {{-- La recherche filtre en direct, sans tenir compte des accents ni des majuscules. --}}
    <div class="conteneur grid grid-cols-[minmax(0,1fr)] gap-8 py-8 tab:py-12 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)] lg:gap-12"
         x-data="{
             q: '',
             normaliser(texte) { return texte.normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[’']/g, ' ').toLowerCase().trim() },
             correspond(el) { const q = this.normaliser(this.q); return q === '' || el.dataset.texte.includes(q) },
             get nbResultats() { this.q; return [...this.$root.querySelectorAll('[data-texte]')].filter((el) => this.correspond(el)).length },
         }">
        <div>
            <div>
                <label for="recherche-faq" class="block font-semibold text-encre">{{ __('public.faq.recherche') }}</label>
                <p id="recherche-faq-aide" class="texte-carte">{{ __('public.faq.recherche_aide') }}</p>
                <input id="recherche-faq" type="search" x-model="q" autocomplete="off"
                       aria-describedby="recherche-faq-aide"
                       class="mt-2 block min-h-[48px] w-full rounded-charte border-2 border-ligne px-4 text-base focus:border-vert focus:ring-0">
            </div>

            <p class="mt-6 rounded-charte bg-fond p-4 text-gris" x-show="nbResultats === 0" x-cloak role="status">{{ __('public.faq.aucun_resultat') }}</p>

            @foreach ($groupes as $cle => $groupe)
                <section aria-labelledby="groupe-{{ $cle }}" class="mt-8"
                         x-show="[...$el.querySelectorAll('[data-texte]')].some((el) => correspond(el))">
                    <h2 id="groupe-{{ $cle }}">{{ $groupe['titre'] }}</h2>
                    <div class="faq-liste">
                        @foreach ($groupe['questions'] as $question)
                            <x-public.question :question="$question['q']"
                                               data-texte="{{ str_replace(['’', '\''], ' ', Str::lower(Str::ascii($question['q'].' '.$question['r']))) }}"
                                               x-show="correspond($el)">
                                {{ $question['r'] }}
                            </x-public.question>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>

        {{-- Contact --}}
        <aside id="contact" class="scroll-mt-6 lg:sticky lg:top-6 lg:self-start" aria-labelledby="contact-titre">
            <div class="carte p-5">
                <h2 id="contact-titre" class="!text-lg">{{ __('public.faq.contact.titre') }}</h2>

                <x-public.contacts />

                @if ($antiFraude = plateforme('messages.messageAntiFraude'))
                    <x-public.alerte variante="attention" class="mt-4">{{ $antiFraude }}</x-public.alerte>
                @endif

                <div id="guichets" class="mt-5 scroll-mt-6 border-t border-ligne pt-4">
                    <h3 class="flex items-center gap-2"><x-public.icone nom="lieu" class="h-5 w-5 text-vert-fonce" />{{ __('public.faq.contact.guichets_titre') }}</h3>
                    <p class="mt-1 text-[0.95rem]">{{ __('public.faq.contact.guichets_texte') }}</p>
                    <a href="{{ route('guichets') }}" class="lien-fort">{{ __('public.faq.contact.guichets_lien') }}</a>
                </div>

                <div class="mt-5 border-t border-ligne pt-4">
                    <a href="{{ route('contact') }}" class="btn-secondaire w-full">{{ __('public.faq.contact.ecrire') }}</a>
                </div>
            </div>
        </aside>
    </div>
</x-public.layout>
