<x-institutionnel.layout :titre="__('institutionnel.menu.contact')" :fil="[['libelle' => __('institutionnel.menu.contact')]]">
    <div class="bo-carte !mt-0">
        @if ($institution->interlocuteur_nom || $institution->interlocuteur_email || $institution->interlocuteur_telephone)
            <p>{{ __('institutionnel.contact.intro') }}</p>
            <dl class="bo-definitions mt-3">
                @if ($institution->interlocuteur_nom)<dt>{{ __('institutionnel.bo.institutions.champs.interlocuteur_nom') }}</dt><dd>{{ $institution->interlocuteur_nom }}</dd>@endif
                @if ($institution->interlocuteur_email)<dt>{{ __('institutionnel.bo.institutions.champs.interlocuteur_email') }}</dt><dd><a href="mailto:{{ $institution->interlocuteur_email }}">{{ $institution->interlocuteur_email }}</a></dd>@endif
                @if ($institution->interlocuteur_telephone)<dt>{{ __('institutionnel.bo.institutions.champs.interlocuteur_telephone') }}</dt><dd><a href="tel:{{ $institution->interlocuteur_telephone }}">{{ $institution->interlocuteur_telephone }}</a></dd>@endif
            </dl>
        @else
            <p class="texte-carte">{{ __('institutionnel.contact.aucun') }}</p>
        @endif
    </div>
</x-institutionnel.layout>
