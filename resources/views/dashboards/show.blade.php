{{-- Tableau de bord des profils sans espace dédié (profils historiques) : état vide honnête. --}}
<x-public.layout :titre="__('public.tableau.titre')">
    <div class="conteneur py-12 tab:py-16">
        <p class="text-center text-sm font-bold uppercase tracking-wide text-vert-fonce">{{ $role->label() }}</p>
        <x-etat-vide icone="utilisateur" :titre="__('public.tableau.bienvenue', ['nom' => Auth::user()->prenoms ?: Auth::user()->name])" :lien="route('accueil')" :libelle-lien="__('public.erreurs.retour_accueil')">
            {{ __('public.tableau.aucun_service') }}
        </x-etat-vide>
    </div>
</x-public.layout>
