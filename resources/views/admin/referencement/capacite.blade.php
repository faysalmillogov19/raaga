@php($k = __('admin.referencement.capacites'))

<x-admin.layout :titre="$k['modifier_titre']" :fil="[['libelle' => $k['titre'], 'url' => route('admin.referencement.capacites', ['site' => $capacite->site_id])], ['libelle' => $k['modifier_titre']]]">
    <form method="POST" action="{{ route('admin.referencement.capacites.update', $capacite) }}" class="bo-carte !mt-0" novalidate>
        @csrf @method('PUT')
        <p class="texte-carte">{{ $k['colonnes']['site'] }} : <span class="font-semibold text-encre">{{ ($capacite->site->code ? $capacite->site->code.' · ' : '').$capacite->site->nom }}</span></p>
        <div class="mt-3 grid gap-4 lg:grid-cols-3">
            @include('admin.referencement._capacite-champs', ['capacite' => $capacite])
        </div>
        <div class="mt-4 flex gap-3">
            <button type="submit" class="bo-bouton bo-bouton-principal">{{ $k['enregistrer'] }}</button>
            <a href="{{ route('admin.referencement.capacites', ['site' => $capacite->site_id]) }}" class="bo-bouton bo-bouton-secondaire">{{ $k['annuler'] }}</a>
        </div>
    </form>
</x-admin.layout>
