@if (! $u->actif)
    <span class="bo-etiquette bo-etiquette-echec">{{ __('admin.utilisateurs.statuts.desactive') }}</span>
@elseif ($u->estVerrouille())
    <span class="bo-etiquette bo-etiquette-attention">{{ __('admin.utilisateurs.statuts.verrouille') }}</span>
@elseif ($invitation && ! $u->password)
    <span class="bo-etiquette bo-etiquette-neutre">{{ __('admin.utilisateurs.statuts.invitation') }}</span>
@else
    <span class="bo-etiquette bo-etiquette-succes">{{ __('admin.utilisateurs.statuts.actif') }}</span>
@endif
