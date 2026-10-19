{{--
    Carte « Investir » de l'accueil (deux thèmes) : présentation des actions visible → valeur indicative, minimum, jauge,
    lien de souscription ou de l'alerte ; sinon annonce permanente « Investir dans Raaga : bientôt » (alerte SMS seule).
    Variables : $investir (null si la présentation est masquée), $d (textes public.demarches).
--}}
@if ($investir)
    <article class="acc-carte acc-demarche demarche-investir">
        <span class="picto-demarche"><x-icon name="piece-pousse" class="h-7 w-7" data-picto="piece-pousse" /></span>
        <x-public.badge-demarche demarche="investir" />
        <h3 class="acc-h2">{{ $d['investir']['titre'] }}</h3>
        @if ($investir['valeur'])
            <ul class="acc-demarche-points">
                <li>{{ __('public.demarches.investir.valeur_indicative', ['prix' => format_fcfa($investir['valeur'])]) }}</li>
                <li>{{ trans_choice('public.demarches.investir.minimum', $investir['minimum'], ['n' => format_nombre($investir['minimum'])]) }}</li>
            </ul>
        @else
            <p data-invest-bientot>{{ $d['investir']['bientot_invest'] }}</p>
        @endif
        @if ($jauge = $investir['jauge'])
            {{-- Jauge de collecte : peut dépasser 100 % de l'objectif (la barre s'arrête au bout). --}}
            <div class="acc-jauge" data-jauge-invest>
                <p class="font-semibold">{{ __('public.demarches.investir.objectif', ['objectif' => format_fcfa($jauge['objectif'])]) }}</p>
                <div class="acc-jauge-barre" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ (int) $jauge['largeur'] }}"
                     aria-valuetext="{{ __('public.demarches.investir.pourcentage', ['pourcentage' => $jauge['libelle']]) }}">
                    <span style="width: {{ round($jauge['largeur'], 1) }}%"></span>
                </div>
                <p>{{ __('public.demarches.investir.collecte', ['collecte' => format_fcfa($jauge['collecte'])]) }} ·
                    <strong data-pourcentage-objectif>{{ __('public.demarches.investir.pourcentage', ['pourcentage' => $jauge['libelle']]) }}</strong></p>
            </div>
            <a href="{{ route('invest.simulateur') }}" class="lien-fort">{{ $d['investir']['simuler'] }}</a>
        @endif
        <p class="acc-demarche-tirage"><x-icon name="check" class="h-5 w-5 shrink-0" /> <span>{{ $d['investir']['dividendes'] }}</span></p>
        <p class="acc-demarche-risque"><x-icon name="danger" class="h-5 w-5 shrink-0" /> <span>{{ $d['investir']['risque'] }}</span></p>
        <a href="{{ $investir['lien'] }}" class="btn-demarche acc-demarche-bouton">{{ $investir['ouverte'] ? $d['investir']['bouton'] : $d['bientot'] }}</a>
        <p class="acc-demarche-rappel">{{ $d['actions'] }}</p>
    </article>
@else
    {{-- Annonce permanente : ni prix, ni lien vers les pages des actions ; seulement l'alerte SMS. --}}
    @php($n = $d['annonce'])
    <article class="acc-carte acc-demarche demarche-investir" data-annonce-investir>
        <span class="picto-demarche"><x-icon name="piece-pousse" class="h-7 w-7" data-picto="piece-pousse" /></span>
        <x-public.badge-demarche demarche="investir" />
        <h3 class="acc-h2">{{ $n['titre'] }}</h3>
        <p>{{ $n['texte'] }}</p>
        @if (session('alerte_enregistree'))
            <p class="acc-demarche-tirage" role="status"><x-icon name="check" class="h-5 w-5 shrink-0" /> <span>{{ __('public.demarches.alerte.enregistree') }}</span></p>
        @else
            <details class="acc-annonce-alerte acc-demarche-bouton" @if ($errors->hasAny(['telephone', 'consentement'])) open @endif>
                <summary class="btn-demarche">{{ $n['bouton'] }}</summary>
                <form method="POST" action="{{ route('souscription.bientot.store') }}" class="mt-4 space-y-3" novalidate>
                    @csrf
                    <div>
                        <label for="annonce-telephone" class="font-semibold"><x-requis /> {{ __('public.demarches.alerte.telephone') }}</label>
                        <input id="annonce-telephone" name="telephone" type="tel" inputmode="tel" autocomplete="tel" value="{{ old('telephone') }}"
                               class="champ mt-1" aria-describedby="annonce-telephone-aide">
                        <p id="annonce-telephone-aide" class="texte-carte">{{ __('public.demarches.alerte.telephone_aide') }}</p>
                        @if ($errors->has('telephone'))<p class="message-erreur" role="alert">{{ $errors->first('telephone') }}</p>@endif
                    </div>
                    <div>
                        <x-public.case nom="consentement" id="annonce-consentement" value="1" :libelle="__('public.demarches.alerte.consentement')" obligatoire aria-required="true" />
                        @if ($errors->has('consentement'))<p class="message-erreur" role="alert">{{ $errors->first('consentement') }}</p>@endif
                    </div>
                    <button type="submit" class="btn-demarche">{{ __('public.demarches.alerte.envoyer') }}</button>
                </form>
            </details>
        @endif
    </article>
@endif
