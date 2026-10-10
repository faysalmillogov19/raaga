@if ($etape === 'succes')
    {{-- Écran de succès : le compte est créé et l'utilisateur connecté. --}}
    <x-public.carte-compte :titre="__('public.compte.inscription.succes_titre')">
        <x-slot:avant>
            <div class="pastille-succes" aria-hidden="true"><x-icon name="check" class="h-8 w-8" /></div>
        </x-slot:avant>

        <p class="compte-intro">{{ __('public.compte.inscription.succes_texte') }}</p>
        <a href="{{ route('espace.verification') }}" class="btn-plein">{{ __('public.compte.inscription.verifier') }}</a>
        <a href="{{ route('espace') }}" class="btn-contour mt-3">{{ __('public.compte.inscription.plus_tard') }}</a>
    </x-public.carte-compte>
@else
    @php
        $libelles = __('public.compte.inscription.etapes');
        $ordre = array_keys($libelles);
        $position = array_search($etape, $ordre, true);
    @endphp

    <x-public.carte-compte :titre="__('public.compte.inscription.titre')"
                           :intro="$etape === 'identite' ? __('public.compte.inscription.identite_intro') : __('public.compte.inscription.intro')">
        <x-slot:avant>
            <ol class="stepper" aria-label="{{ __('public.compte.inscription.etape_courante', ['n' => $position + 1, 'libelle' => $libelles[$etape]]) }}">
                @foreach ($libelles as $cle => $libelle)
                    <li @class(['fait' => $loop->index < $position, 'en-cours' => $loop->index === $position])
                        @if ($loop->index === $position) aria-current="step" @endif>{{ $libelle }}</li>
                @endforeach
            </ol>
        </x-slot:avant>

        @if ($etape === 'telephone')
            <x-public.compte.etape-telephone :action="route('inscription.telephone')"
                                             :valeur="$telephone ? \App\Support\Telephone::formaterLocal($telephone) : ''">
                @if (session('compte.existe'))
                    <p class="alerte-compte">
                        {{ __('public.compte.inscription.existe') }}
                        <a href="{{ route('connexion') }}">{{ __('public.compte.inscription.se_connecter') }}</a>
                    </p>
                @endif
            </x-public.compte.etape-telephone>

        @elseif ($etape === 'code')
            <x-public.compte.etape-code :action="route('inscription.code')" :renvoyer="route('inscription.renvoyer')"
                                        :telephone="$telephone" :secondes="$secondesRenvoi"
                                        :bouton="__('public.compte.inscription.continuer')">
                <form method="POST" action="{{ route('inscription.retour') }}">
                    @csrf
                    <button type="submit" class="lien-action">← {{ __('public.compte.retour') }}</button>
                </form>
            </x-public.compte.etape-code>

        @else
            @php
                $valeurs = array_merge($saisie, array_filter(old() ?: [], fn ($cle) => in_array($cle, ['nom', 'prenoms', 'date_naissance', 'pays_residence', 'region'], true), ARRAY_FILTER_USE_KEY));
                $messages = __('public.compte.inscription.erreurs');
                $messages['majeur'] = __('public.compte.inscription.erreurs.majeur', ['age' => config('raaga.inscription.age_minimum')]);
            @endphp
            <form method="POST" action="{{ route('inscription.identite') }}" class="formulaire" novalidate
                  x-data="formulaireIdentite(@js(['valeurs' => $valeurs, 'messages' => $messages, 'ageMinimum' => (int) config('raaga.inscription.age_minimum')]))"
                  @submit="valider($event)">
                @csrf

                @foreach ([
                    ['nom', 'text', 'family-name'],
                    ['prenoms', 'text', 'given-name'],
                    ['date_naissance', 'date', 'bday'],
                ] as [$champ, $type, $autocomplete])
                    <div class="formulaire-champ">
                        <label for="{{ $champ }}">{{ __('public.compte.inscription.'.$champ) }}</label>
                        <input id="{{ $champ }}" name="{{ $champ }}" type="{{ $type }}" autocomplete="{{ $autocomplete }}"
                               class="champ @error($champ) est-invalide @enderror" :class="{ 'est-invalide': erreurs.{{ $champ }} }"
                               value="{{ $valeurs[$champ] ?? '' }}" required aria-describedby="{{ $champ }}-erreur"
                               @if ($champ === 'nom') :value="nom" @input="majuscules($event)" autocapitalize="characters" @endif
                               @if ($champ === 'date_naissance') max="{{ now()->subYears((int) config('raaga.inscription.age_minimum'))->toDateString() }}" @endif>
                        <p id="{{ $champ }}-erreur" class="message-erreur" role="alert"
                           x-show="erreurs.{{ $champ }} || @js($errors->has($champ))"
                           x-text="erreurs.{{ $champ }} || @js($errors->first($champ))">{{ $errors->first($champ) }}</p>
                    </div>
                @endforeach

                <div class="formulaire-champ">
                    <label for="pays_residence">{{ __('public.compte.inscription.pays_residence') }}</label>
                    <select id="pays_residence" name="pays_residence" class="champ @error('pays_residence') est-invalide @enderror"
                            x-model="pays" aria-describedby="pays_residence-erreur pays-diaspora">
                        @foreach ($pays as $code => $nom)
                            <option value="{{ $code }}" @selected(($valeurs['pays_residence'] ?? 'BF') === $code)>{{ $nom }}</option>
                        @endforeach
                    </select>
                    <p id="pays-diaspora" class="aide-champ" x-show="diaspora" @if (($valeurs['pays_residence'] ?? 'BF') === 'BF') x-cloak @endif>{{ __('public.compte.inscription.diaspora') }}</p>
                    <p id="pays_residence-erreur" class="message-erreur" role="alert"
                       x-show="erreurs.pays_residence || @js($errors->has('pays_residence'))"
                       x-text="erreurs.pays_residence || @js($errors->first('pays_residence'))">{{ $errors->first('pays_residence') }}</p>
                </div>

                <div class="formulaire-champ" x-show="! diaspora" @if (($valeurs['pays_residence'] ?? 'BF') !== 'BF') x-cloak @endif>
                    <label for="region">{{ __('public.compte.inscription.region') }}</label>
                    <select id="region" name="region" class="champ @error('region') est-invalide @enderror"
                            :class="{ 'est-invalide': erreurs.region }" :disabled="diaspora" aria-describedby="region-erreur">
                        <option value="">{{ __('public.compte.inscription.choisir') }}</option>
                        @foreach ($regions as $region)
                            <option value="{{ $region }}" @selected(($valeurs['region'] ?? '') === $region)>{{ $region }}</option>
                        @endforeach
                    </select>
                    <p id="region-erreur" class="message-erreur" role="alert"
                       x-show="erreurs.region || @js($errors->has('region'))"
                       x-text="erreurs.region || @js($errors->first('region'))">{{ $errors->first('region') }}</p>
                </div>

                <label class="case-conditions" for="conditions">
                    <input id="conditions" name="conditions" type="checkbox" value="1" aria-describedby="conditions-erreur">
                    <span>{!! __('public.compte.inscription.conditions', [
                        'cgu' => '<a href="'.e(route('legal.mentions')).'" target="_blank">'.e(__('public.compte.inscription.cgu')).'</a>',
                        'donnees' => '<a href="'.e(route('legal.donnees')).'" target="_blank">'.e(__('public.compte.inscription.politique')).'</a>',
                    ]) !!}</span>
                </label>
                <p id="conditions-erreur" class="message-erreur" role="alert"
                   x-show="erreurs.conditions || @js($errors->has('conditions'))"
                   x-text="erreurs.conditions || @js($errors->first('conditions'))">{{ $errors->first('conditions') }}</p>

                <div class="boutons-etape">
                    <button type="submit" name="vers" value="telephone" formaction="{{ route('inscription.retour') }}" formnovalidate
                            class="btn-contour">{{ __('public.compte.retour') }}</button>
                    <button type="submit" class="btn-plein" :disabled="envoi">
                        <span x-text="envoi ? @js(__('public.compte.patientez')) : @js(__('public.compte.inscription.bouton'))">{{ __('public.compte.inscription.bouton') }}</span>
                    </button>
                </div>
            </form>
        @endif

        <x-slot:pied>
            {{ __('public.compte.inscription.deja') }} <a href="{{ route('connexion') }}">{{ __('public.compte.inscription.se_connecter') }}</a>
        </x-slot:pied>
    </x-public.carte-compte>
@endif
