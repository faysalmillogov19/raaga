@php($k = __('admin.referencement.capacites'))

<x-admin.layout :titre="$k['titre']" :fil="[['libelle' => $k['titre']]]">
    <div class="bo-carte !mt-0">
        <p class="texte-carte max-w-3xl">{{ $k['intro'] }}</p>
        @if ($sites->isEmpty())
            <x-etat-vide icone="info" :titre="$k['vide_titre']" :lien="auth()->user()->can(\App\Enums\Droit::SitesGerer->value) ? route('admin.referencement.imports') : null" :libelle-lien="$k['importer']">{{ $k['vide'] }}</x-etat-vide>
        @else
            <form method="GET" action="{{ route('admin.referencement.capacites') }}" class="mt-4 flex flex-wrap items-end gap-3">
                <div class="formulaire-champ !mt-0">
                    <label for="site">{{ $k['choisir'] }}</label>
                    <select id="site" name="site" class="champ">
                        @foreach ($sites as $s)
                            <option value="{{ $s->id }}" @selected($site?->id === $s->id)>{{ $s->code ? $s->code.' · ' : '' }}{{ $s->nom }} ({{ trans_choice('admin.zones.capacites', $s->capacites_count, ['n' => format_nombre($s->capacites_count)]) }})</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="bo-bouton bo-bouton-secondaire">{{ $k['afficher'] }}</button>
                @can(\App\Enums\Droit::SitesGerer->value)
                    <a href="{{ route('admin.referencement.imports') }}" class="bo-bouton bo-bouton-secondaire">{{ $k['importer'] }}</a>
                @endcan
            </form>
        @endif
    </div>

    @if ($site)
        @include('admin.sites._capacites', ['site' => $site, 'capacites' => $capacites, 'comparaison' => $comparaison])
    @endif
</x-admin.layout>
