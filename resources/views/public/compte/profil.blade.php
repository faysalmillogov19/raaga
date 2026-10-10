<x-public.layout :titre="__('public.compte.profil.titre')">
    <x-public.page-entete :titre="__('public.compte.profil.titre')" :intro="__('public.compte.profil.intro')"
                          :fil="[['libelle' => __('public.menu.espace'), 'url' => route('espace')], ['libelle' => __('public.compte.profil.titre')]]" />

    @php
        $messages = __('public.compte.inscription.erreurs');
        $messages['majeur'] = __('public.compte.inscription.erreurs.majeur', ['age' => config('raaga.inscription.age_minimum')]);
        $valeurs = [
            'nom' => old('nom', $user->nom),
            'prenoms' => old('prenoms', $user->prenoms),
            'date_naissance' => old('date_naissance', $user->date_naissance?->toDateString()),
            'pays_residence' => old('pays_residence', $user->pays_residence ?? 'BF'),
            'region' => old('region', $user->region),
        ];
        $kycEngage = in_array($user->statut_kyc, [\App\Enums\StatutKyc::Valide, \App\Enums\StatutKyc::EnAttente], true);
    @endphp

    <div class="compte-fond !py-10">
        <div class="mx-auto max-w-[640px] space-y-6">
            @if (session('succes'))
                <p class="alerte-compte alerte-compte-info !mt-0" role="status">{{ session('succes') }}</p>
            @endif

            {{-- Informations personnelles --}}
            <section class="compte-carte !max-w-none" aria-labelledby="identite-titre">
                <h2 id="identite-titre">{{ __('public.compte.profil.identite') }}</h2>
                <p class="compte-intro">{{ __('public.compte.profil.identite_aide') }}</p>
                @if ($kycEngage)
                    <x-public.alerte variante="attention" class="mt-4">{{ __('public.compte.profil.kyc_attention') }}</x-public.alerte>
                @endif

                <form method="POST" action="{{ route('espace.profil.update') }}" class="formulaire" novalidate
                      x-data="formulaireIdentite(@js(['valeurs' => $valeurs, 'messages' => $messages, 'ageMinimum' => (int) config('raaga.inscription.age_minimum')]))"
                      @submit="valider($event)">
                    @csrf

                    @foreach ([['nom', 'text', 'family-name'], ['prenoms', 'text', 'given-name'], ['date_naissance', 'date', 'bday']] as [$champ, $type, $autocomplete])
                        <div class="formulaire-champ">
                            <label for="{{ $champ }}">{{ __('public.compte.inscription.'.$champ) }}</label>
                            <input id="{{ $champ }}" name="{{ $champ }}" type="{{ $type }}" autocomplete="{{ $autocomplete }}" required
                                   class="champ @error($champ) est-invalide @enderror" :class="{ 'est-invalide': erreurs.{{ $champ }} }"
                                   value="{{ $valeurs[$champ] ?? '' }}" aria-describedby="{{ $champ }}-erreur"
                                   @if ($champ === 'nom') :value="nom" @input="majuscules($event)" autocapitalize="characters" @endif>
                            <p id="{{ $champ }}-erreur" class="message-erreur" role="alert"
                               x-show="erreurs.{{ $champ }} || @js($errors->has($champ))"
                               x-text="erreurs.{{ $champ }} || @js($errors->first($champ))">{{ $errors->first($champ) }}</p>
                        </div>
                    @endforeach

                    <div class="formulaire-champ">
                        <label for="pays_residence">{{ __('public.compte.inscription.pays_residence') }}</label>
                        <select id="pays_residence" name="pays_residence" class="champ" x-model="pays">
                            @foreach ($pays as $code => $nom)
                                <option value="{{ $code }}" @selected($valeurs['pays_residence'] === $code)>{{ $nom }}</option>
                            @endforeach
                        </select>
                        <p class="aide-champ" x-show="diaspora" @if ($valeurs['pays_residence'] === 'BF') x-cloak @endif>{{ __('public.compte.inscription.diaspora') }}</p>
                    </div>

                    <div class="formulaire-champ" x-show="! diaspora" @if ($valeurs['pays_residence'] !== 'BF') x-cloak @endif>
                        <label for="region">{{ __('public.compte.inscription.region') }}</label>
                        <select id="region" name="region" class="champ @error('region') est-invalide @enderror" :disabled="diaspora">
                            <option value="">{{ __('public.compte.inscription.choisir') }}</option>
                            @foreach ($regions as $region)
                                <option value="{{ $region }}" @selected($valeurs['region'] === $region)>{{ $region }}</option>
                            @endforeach
                        </select>
                        <p class="message-erreur" role="alert" x-show="erreurs.region || @js($errors->has('region'))"
                           x-text="erreurs.region || @js($errors->first('region'))">{{ $errors->first('region') }}</p>
                    </div>

                    <div class="formulaire-champ">
                        <label for="email">{{ __('public.compte.profil.email') }}</label>
                        <input id="email" name="email" type="email" autocomplete="email" value="{{ old('email', $user->email) }}"
                               class="champ @error('email') est-invalide @enderror">
                        @error('email') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
                    </div>

                    {{-- Le formulaire partagé avec l'inscription attend une case « conditions » : déjà acceptées à l'inscription. --}}
                    <input type="checkbox" name="conditions" checked hidden aria-hidden="true" tabindex="-1">

                    <button type="submit" class="btn-plein" :disabled="envoi">
                        <span x-text="envoi ? @js(__('public.compte.patientez')) : @js(__('public.compte.profil.enregistrer'))">{{ __('public.compte.profil.enregistrer') }}</span>
                    </button>
                </form>

                <p class="compte-pied !text-left">{{ __('public.compte.profil.role', ['role' => $user->codeRole()->label()]) }}</p>
            </section>

            {{-- Téléphone --}}
            <section id="telephone" class="compte-carte !max-w-none scroll-mt-6" aria-labelledby="telephone-titre">
                <h2 id="telephone-titre">{{ __('public.compte.profil.telephone_titre') }}</h2>
                <p class="compte-intro">
                    {!! __('public.compte.profil.telephone_actuel', ['telephone' => '<strong class="whitespace-nowrap">'.e($user->telephone ? \App\Support\Telephone::formater($user->telephone) : '—').'</strong>']) !!}
                </p>
                <p class="aide-champ">{{ __('public.compte.profil.telephone_aide') }}</p>

                @if ($nouveauTelephone)
                    <x-public.compte.etape-code :action="route('espace.profil.telephone.confirmer')" :renvoyer="route('espace.profil.telephone.renvoyer')"
                                                :telephone="$nouveauTelephone" :secondes="$secondesRenvoi"
                                                :bouton="__('public.compte.profil.confirmer_telephone')">
                        <form method="POST" action="{{ route('espace.profil.telephone.annuler') }}">
                            @csrf
                            <button type="submit" class="lien-action">{{ __('public.compte.profil.annuler') }}</button>
                        </form>
                    </x-public.compte.etape-code>
                @else
                    <x-public.compte.etape-telephone :action="route('espace.profil.telephone')" :focus="false" />
                @endif
            </section>
        </div>
    </div>
</x-public.layout>
