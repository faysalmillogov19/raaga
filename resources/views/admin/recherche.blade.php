@php($c = __('admin.cadre'))

<x-admin.layout :titre="$c['recherche_titre']" :fil="[['libelle' => $c['recherche_titre']]]"
                :sous-titre="$q !== '' ? __('admin.cadre.recherche_resultats', ['q' => $q]) : $c['recherche_saisir']">
    @if ($q !== '' && $resultats === [])
        <div class="bo-carte"><x-etat-vide icone="info" :titre="$c['recherche_titre']">{{ __('admin.cadre.recherche_vide', ['q' => $q]) }}</x-etat-vide></div>
    @endif
    @foreach ($resultats as $rubrique => $lignes)
        <section class="bo-carte" aria-labelledby="rubrique-{{ $rubrique }}">
            <h2 id="rubrique-{{ $rubrique }}" class="!text-[1.05rem]">{{ $c['rubriques'][$rubrique] }} ({{ format_nombre($lignes->count()) }})</h2>
            <ul class="mt-2">
                @foreach ($lignes as $l)
                    <li><a href="{{ $l['url'] }}" class="adm-panneau-lien"><span class="font-semibold">{{ $l['libelle'] }}</span><span class="texte-carte ml-3">{{ $l['detail'] }}</span></a></li>
                @endforeach
            </ul>
        </section>
    @endforeach
</x-admin.layout>
