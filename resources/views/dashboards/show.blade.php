{{-- Tableau de bord des profils sans espace dédié (agent de guichet, profils historiques) : état vide honnête. --}}
<x-public.layout :titre="$role === \App\Enums\Role::AgentGuichet ? __('public.etats_vides.guichet.page') : __('public.tableau.titre')">
    <div class="conteneur py-12 tab:py-16">
        <p class="text-center text-sm font-bold uppercase tracking-wide text-vert-fonce">{{ $role->label() }}</p>
        @if ($role === \App\Enums\Role::AgentGuichet)
            <x-etat-vide icone="lieu" :titre="__('public.etats_vides.guichet.titre')" :lien="route('guichets')" :libelle-lien="__('public.etats_vides.liens.guichets')">
                {{ __('public.etats_vides.guichet.texte') }}
            </x-etat-vide>
        @else
            <x-etat-vide icone="utilisateur" :titre="__('public.tableau.bienvenue', ['nom' => Auth::user()->prenoms ?: Auth::user()->name])" :lien="route('accueil')" :libelle-lien="__('public.erreurs.retour_accueil')">
                {{ __('public.tableau.aucun_service') }}
            </x-etat-vide>
        @endif
    </div>
</x-public.layout>
