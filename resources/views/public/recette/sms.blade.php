{{-- SMS de recette : messages simulés, du plus récent au plus ancien ; codes de confirmation mis en avant. Jamais en production. --}}
@php($titre = __('public.recette_sms.titre'))

<x-public.layout :titre="$titre">
    <x-public.page-entete :titre="$titre" :intro="__('public.recette_sms.intro')" :fil="[['libelle' => $titre]]" />

    <div class="conteneur max-w-3xl py-8"
         x-data="{
            messages: @js($messages),
            copie: null,
            init() { setInterval(() => this.actualiser(), 3000) },
            async actualiser() {
                try { this.messages = await (await fetch(@js(route('recette.sms')), { headers: { Accept: 'application/json' } })).json() } catch { /* nouvel essai au prochain passage */ }
            },
            heure(d) { return new Date(d).toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit' }) },
            async copier(code) { try { await navigator.clipboard.writeText(code); this.copie = code; setTimeout(() => this.copie = null, 2000) } catch {} },
         }">
        <p class="texte-carte" role="status">{{ __('public.recette_sms.actualisation') }}</p>

        <p x-show="messages.length === 0" class="carte mt-4 p-5 text-center" @if ($messages) x-cloak @endif>{{ __('public.recette_sms.vide') }}</p>

        <ul class="mt-4 space-y-3" aria-live="polite">
            <template x-for="(m, i) in messages" :key="m.date + i">
                <li class="carte p-4" :class="i === 0 && 'border-2 border-vert-fonce'">
                    <div class="flex flex-wrap items-baseline justify-between gap-2 text-sm text-gris">
                        <span class="font-semibold text-encre" x-text="m.telephone"></span>
                        <span x-text="heure(m.date)"></span>
                    </div>
                    <template x-if="m.code">
                        <div class="mt-2 flex flex-wrap items-center gap-3">
                            <span class="font-mono text-3xl font-extrabold tracking-[0.3em] text-vert-fonce" x-text="m.code"></span>
                            <button type="button" class="btn-secondaire" @click="copier(m.code)"
                                    x-text="copie === m.code ? @js(__('public.recette_sms.copie')) : @js(__('public.recette_sms.copier'))"></button>
                        </div>
                    </template>
                    <p class="mt-2 text-[0.95rem]" x-text="m.message"></p>
                </li>
            </template>
        </ul>
    </div>
</x-public.layout>
