{{--
    RAAGA Invest · simulateur : nombre d'actions × valeur indicative par action = montant, et part de l'objectif de
    collecte (indicative). Calcul au serveur (formulaire GET, sans JavaScript) et mis à jour à la saisie. Puis, pour un
    citoyen connecté, la manifestation d'intérêt : simulation non engageante, référence attribuée, aucun paiement.
--}}
@php($v = __('public.invest'))

<x-public.layout :titre="$v['simulateur']['titre']">
    <x-public.page-entete :titre="$v['simulateur']['titre']" :intro="$v['simulateur']['intro']" :fil="[['libelle' => __('public.menu.investir'), 'url' => route('offre')], ['libelle' => $v['simulateur']['titre']]]" />

    <div class="conteneur max-w-3xl space-y-8 py-8 tab:py-12">
        <x-public.mentions-invest />

        @if ($parametres === null)
            <x-public.alerte variante="info" data-simulateur-indisponible><p>{{ $v['simulateur']['indisponible'] }}</p></x-public.alerte>
        @else
            <section class="carte p-5 tab:p-6" aria-labelledby="simulation-titre"
                     x-data="{ nombre: {{ $nombre }}, valeur: {{ $parametres['valeur_action'] }}, objectif: {{ $parametres['objectif_collecte'] }},
                               get montant() { return Math.max(0, Math.floor(this.nombre || 0)) * this.valeur },
                               fcfa(n) { return new Intl.NumberFormat('fr-FR').format(n).replace(/\s/g, ' ') + ' FCFA' },
                               pct(n) { return new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 2 }).format(n) + ' %' } }">
                <h2 id="simulation-titre">{{ $v['simulateur']['calcul'] }}</h2>
                <form method="GET" action="{{ route('invest.simulateur') }}" class="mt-4 grid gap-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
                    <x-public.champ nom="actions" :libelle="$v['simulateur']['nombre']" type="number" min="1" max="10000000" :valeur="$nombre" x-model.number="nombre" required />
                    <button type="submit" class="btn-secondaire">{{ $v['simulateur']['calculer'] }}</button>
                </form>
                <dl class="mt-5 grid gap-3 sm:grid-cols-3" aria-live="polite">
                    <div><dt class="texte-carte">{{ $v['simulateur']['valeur'] }}</dt><dd class="font-bold">{{ format_fcfa($parametres['valeur_action']) }}</dd></div>
                    <div><dt class="texte-carte">{{ $v['simulateur']['montant'] }}</dt>
                        <dd class="text-xl font-extrabold text-vert-fonce" data-montant x-text="fcfa(montant)">{{ format_fcfa($simulation['montant']) }}</dd></div>
                    <div><dt class="texte-carte">{{ $v['simulateur']['part_objectif'] }}</dt>
                        <dd class="font-bold" data-part-objectif x-text="pct(montant * 100 / objectif)">{{ format_nombre($simulation['part_objectif'], 2) }}&nbsp;%</dd></div>
                </dl>
                <p class="texte-carte mt-3">{{ __('public.invest.simulateur.objectif', ['objectif' => format_fcfa($parametres['objectif_collecte'])]) }}
                    @if ($parametres['plafond_global']) {{ __('public.invest.simulateur.plafond', ['plafond' => format_fcfa($parametres['plafond_global'])]) }}@endif</p>
            </section>

            {{-- Manifestation d'intérêt : citoyen connecté, simulation non engageante, aucun paiement. --}}
            <section class="carte p-5 tab:p-6" aria-labelledby="interet-titre">
                <h2 id="interet-titre">{{ $v['interet']['titre'] }}</h2>
                <p class="mt-2">{{ $v['interet']['intro'] }}</p>
                @if ($citoyen)
                    <form method="POST" action="{{ route('invest.interet') }}" class="mt-4 space-y-4" data-formulaire-interet>
                        @csrf
                        <fieldset>
                            <legend class="font-bold text-encre">{{ $v['attributs']['profil'] }}</legend>
                            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                @foreach ($profils as $profil)
                                    <label class="inline-flex min-h-[44px] items-center gap-3">
                                        <input type="radio" name="profil" value="{{ $profil->value }}" @checked(old('profil') === $profil->value) required> {{ $profil->label() }}
                                    </label>
                                @endforeach
                            </div>
                            @error('profil') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
                        </fieldset>
                        <x-public.champ nom="pays_residence" :libelle="ucfirst($v['attributs']['pays_residence'])" :valeur="old('pays_residence', 'Burkina Faso')" maxlength="80" required />
                        <x-public.champ nom="nombre_actions" :libelle="ucfirst($v['attributs']['nombre_actions'])" type="number" min="1" max="10000000" :valeur="old('nombre_actions', $nombre)" required />
                        <div>
                            <x-public.case nom="simulation_comprise" :libelle="$v['interet']['case']" value="1" required />
                            @error('simulation_comprise') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
                        </div>
                        <button type="submit" class="btn-primaire">{{ $v['interet']['envoyer'] }}</button>
                        <p class="texte-carte">{{ $v['interet']['aucun_paiement'] }}</p>
                    </form>
                @else
                    <p class="mt-3"><a href="{{ route('connexion') }}" class="lien-fort">{{ $v['interet']['se_connecter'] }}</a></p>
                @endif
            </section>
        @endif
    </div>
</x-public.layout>
