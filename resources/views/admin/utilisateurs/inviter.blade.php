<x-admin.layout :titre="__('admin.utilisateurs.formulaire.titre')" :fil="[
    ['libelle' => __('admin.utilisateurs.titre'), 'url' => route('admin.utilisateurs')],
    ['libelle' => __('admin.utilisateurs.inviter')],
]">
    <div class="bo-carte max-w-[640px]">
        <p class="texte-carte">{{ __('admin.utilisateurs.formulaire.intro') }}</p>

        <form method="POST" action="{{ route('admin.utilisateurs.store') }}" class="formulaire" x-data="{ envoi: false }" @submit="envoi = true">
            @csrf
            <div class="formulaire-champ">
                <label for="nom">{{ __('admin.utilisateurs.formulaire.nom') }}</label>
                <input id="nom" name="nom" type="text" value="{{ old('nom') }}" required maxlength="150" class="champ @error('nom') est-invalide @enderror">
                @error('nom') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
            </div>
            <div class="formulaire-champ">
                <label for="email">{{ __('admin.utilisateurs.formulaire.email') }}</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required class="champ @error('email') est-invalide @enderror">
                @error('email') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
            </div>
            <div class="formulaire-champ">
                <label for="role">{{ __('admin.utilisateurs.formulaire.role') }}</label>
                <select id="role" name="role" class="champ @error('role') est-invalide @enderror">
                    @foreach ($roles as $role)
                        <option value="{{ $role->value }}" @selected(old('role') === $role->value)>{{ $role->badge() }} — {{ $role->description() }}</option>
                    @endforeach
                </select>
                @error('role') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
            </div>
            <div class="mt-6 flex gap-3">
                <x-admin.bouton variante="principal" type="submit" x-bind:disabled="envoi">{{ __('admin.utilisateurs.formulaire.envoyer') }}</x-admin.bouton>
                <x-admin.bouton variante="contour" href="{{ route('admin.utilisateurs') }}">{{ __('admin.confirmation.annuler') }}</x-admin.bouton>
            </div>
        </form>
    </div>
</x-admin.layout>
