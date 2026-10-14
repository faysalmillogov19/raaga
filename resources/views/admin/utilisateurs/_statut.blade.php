@if (! $u->actif)
    <x-admin.badge-statut :statut="__('admin.utilisateurs.statuts.desactive')" variante="erreur" />
@elseif ($u->estVerrouille())
    <x-admin.badge-statut :statut="__('admin.utilisateurs.statuts.verrouille')" variante="avertissement" />
@elseif ($invitation && ! $u->password)
    <x-admin.badge-statut :statut="__('admin.utilisateurs.statuts.invitation')" variante="neutre" />
@else
    <x-admin.badge-statut :statut="__('admin.utilisateurs.statuts.actif')" variante="ok" />
@endif
