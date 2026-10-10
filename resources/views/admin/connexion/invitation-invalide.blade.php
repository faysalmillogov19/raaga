<x-admin.carte-connexion :espace="$espace" :titre="__('admin.invitation.invalide_titre')">
    <p class="alerte-compte" role="alert">{{ __('admin.invitation.invalide_texte') }}</p>
    <p class="compte-pied"><a href="{{ route($espace->route('connexion')) }}">{{ __('admin.connexion.retour') }}</a></p>
</x-admin.carte-connexion>
