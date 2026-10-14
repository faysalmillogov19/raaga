@php
    $p = __('admin.profil');
    $min = config('raaga.backoffice.mot_de_passe_min');
    $theme = $user->theme_backoffice ?? 'clair';
@endphp

<x-admin.layout :titre="$p['titre']" :fil="[['libelle' => $p['titre']]]">
    <div class="grid gap-[18px] xl:grid-cols-2">
        {{-- Informations : modification en fenêtre (mot de passe actuel demandé). --}}
        <section class="bo-carte !mt-0" aria-labelledby="infos-titre">
            <h2 id="infos-titre" class="!text-[1.15rem]">{{ $p['informations'] }}</h2>
            <p class="texte-carte mt-1">{{ $p['informations_intro'] }}</p>
            <dl class="bo-definitions mt-3">
                <dt>{{ $p['nom'] }}</dt><dd>{{ $user->name }}</dd>
                <dt>{{ $p['email'] }}</dt><dd>{{ $user->email }}</dd>
                <dt>{{ $p['role'] }}</dt><dd>{{ $user->codeRole()->label() }} <span class="texte-carte block font-normal">{{ $p['role_aide'] }}</span></dd>
            </dl>
            <div class="mt-4">
                <x-admin.fenetre id="modifier-informations" :titre="$p['informations']" :action="route('admin.profil.update')" :bouton="$p['enregistrer']"
                                 declencheur-variante="secondaire" :ouvrir="$errors->getBag('informations')->any()">
                    <x-slot:declencheur>{{ __('admin.parametrage.actions.modifier') }}</x-slot:declencheur>
                    <x-admin.champ nom="name" :libelle="$p['nom']" :valeur="$user->name" maxlength="150" autocomplete="name" required />
                    <x-admin.champ nom="email" :libelle="$p['email']" type="email" :valeur="$user->email" autocomplete="username" required class="mt-4" />
                    <x-admin.champ nom="mot_de_passe_actuel" id="mdp-infos" :libelle="$p['mot_de_passe_actuel']" type="password" autocomplete="current-password" required class="mt-4" />
                </x-admin.fenetre>
            </div>
        </section>

        <div class="space-y-[18px]">
            {{-- Mot de passe --}}
            <section class="bo-carte !mt-0" aria-labelledby="mdp-titre">
                <h2 id="mdp-titre" class="!text-[1.15rem]">{{ $p['mot_de_passe'] }}</h2>
                <p class="texte-carte mt-1">{{ __('admin.profil.mot_de_passe_aide', ['n' => $min]) }}</p>
                <div class="mt-4">
                    <x-admin.fenetre id="changer-mot-de-passe" :titre="$p['mot_de_passe']" :action="route('admin.profil.mot-de-passe')" :bouton="$p['changer']"
                                     declencheur-variante="secondaire" :ouvrir="$errors->getBag('motDePasse')->any()">
                        <x-slot:declencheur>{{ $p['changer'] }}</x-slot:declencheur>
                        <x-admin.champ nom="mot_de_passe_actuel" id="mdp-actuel" :libelle="$p['mot_de_passe_actuel']" type="password" autocomplete="current-password" required />
                        <x-admin.champ nom="password" id="mdp-nouveau" :libelle="$p['nouveau_mot_de_passe']" type="password" minlength="{{ $min }}" autocomplete="new-password" required
                                       :aide="__('admin.profil.mot_de_passe_aide', ['n' => $min])" class="mt-4" />
                        <x-admin.champ nom="password_confirmation" id="mdp-confirmation" :libelle="$p['confirmation']" type="password" autocomplete="new-password" required class="mt-4" />
                    </x-admin.fenetre>
                </div>
            </section>

            {{-- Double authentification --}}
            <section class="bo-carte" aria-labelledby="securite-titre">
                <h2 id="securite-titre" class="!text-[1.15rem]">{{ $p['securite'] }}</h2>
                <p class="mt-2">{{ $user->totpActive() ? __('admin.profil.securite_active', ['date' => $user->totp_active_le->format('d/m/Y')]) : $p['securite_inactive'] }}</p>
            </section>

            {{-- Préférences : thème du back-office, mémorisé pour le compte. --}}
            <section class="bo-carte" id="preferences" aria-labelledby="preferences-titre">
                <h2 id="preferences-titre" class="!text-[1.15rem]">{{ __('admin.cadre.preferences_titre') }}</h2>
                <p class="texte-carte mt-1">{{ __('admin.cadre.preferences_aide') }}</p>
                <p class="mt-2 font-semibold">{{ __('admin.cadre.theme_'.$theme) }}</p>
                <div class="mt-4">
                    <x-admin.fenetre id="changer-theme" :titre="__('admin.cadre.preferences_titre')" :action="route('admin.preferences.theme')" :bouton="__('admin.cadre.enregistrer')"
                                     declencheur-variante="secondaire">
                        <x-slot:declencheur>{{ __('admin.cadre.theme') }}</x-slot:declencheur>
                        <x-admin.champ nom="theme" :libelle="__('admin.cadre.theme')" type="radio" :valeur="$theme"
                                       :options="collect(\App\Http\Controllers\Admin\CadreController::THEMES)->mapWithKeys(fn ($t) => [$t => __('admin.cadre.theme_'.$t)])->all()" />
                    </x-admin.fenetre>
                </div>
            </section>
        </div>
    </div>
</x-admin.layout>
