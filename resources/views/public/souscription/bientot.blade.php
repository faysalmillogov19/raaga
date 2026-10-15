{{-- Souscription d'actions pas encore ouverte : inscription à l'alerte SMS (numéro et consentement). --}}
@php($t = __('public.demarches.alerte'))

<x-public.layout :titre="$t['titre']">
    <x-public.page-entete :titre="$t['titre']" :fil="[['libelle' => __('public.menu.investir'), 'url' => route('offre')], ['libelle' => $t['titre']]]" />

    <div class="conteneur max-w-xl py-8 tab:py-12">
        @if (session('alerte_enregistree'))
            <x-public.alerte variante="info" role="status">{{ $t['enregistree'] }}</x-public.alerte>
        @else
            <div class="carte p-5 tab:p-6">
                <p>{{ $t['intro'] }}</p>
                <form method="POST" action="{{ route('souscription.bientot.store') }}" class="mt-5 space-y-4" novalidate>
                    @csrf
                    <x-public.champ nom="telephone" :libelle="$t['telephone']" type="tel" :aide="$t['telephone_aide']" />
                    <div>
                        <x-public.case nom="consentement" value="1" :libelle="$t['consentement']" />
                        @error('consentement')<p class="message-erreur" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <button type="submit" class="btn-primaire w-full sm:w-auto">{{ $t['envoyer'] }}</button>
                </form>
            </div>
        @endif
    </div>
</x-public.layout>
