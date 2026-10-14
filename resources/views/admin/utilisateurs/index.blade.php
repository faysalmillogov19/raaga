@php($u = __('admin.utilisateurs'))
<x-admin.layout :titre="$u['titre']" :sous-titre="$u['intro']['backoffice']" :fil="[['libelle' => $u['titre']]]">
    <x-slot:actions>
        {{-- Invitation : nom, adresse électronique, rôle ; le lien d'activation est envoyé par courriel. --}}
        <x-admin.fenetre id="inviter-utilisateur" :titre="$u['formulaire']['titre']" :action="route('admin.utilisateurs.store')" :bouton="$u['formulaire']['envoyer']"
                         declencheur-icone="plus" :ouvrir="request()->boolean('inviter')">
            <x-slot:declencheur>{{ $u['inviter'] }}</x-slot:declencheur>
            <p class="texte-carte mb-4">{{ $u['formulaire']['intro'] }}</p>
            <x-admin.champ nom="nom" :libelle="$u['formulaire']['nom']" maxlength="150" required autocomplete="off" />
            <x-admin.champ nom="email" :libelle="$u['formulaire']['email']" type="email" required autocomplete="off" class="mt-4" />
            <x-admin.champ nom="role" :libelle="$u['formulaire']['role']" type="liste" class="mt-4" required
                           :options="collect($roles)->mapWithKeys(fn ($r) => [$r->value => $r->badge().' — '.$r->description()])->all()" />
        </x-admin.fenetre>
    </x-slot:actions>

    <div class="bo-carte !mt-0">
        <x-tableau :t="$tableau" :libelle="$u['titre']" />
    </div>
</x-admin.layout>
