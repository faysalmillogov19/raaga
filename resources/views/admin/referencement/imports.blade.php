{{-- Imports d'une entité (sous-onglet du menu « Imports ») : boutons d'import et historique des imports de l'entité. --}}
@php
    $i = __('admin.imports');
    $gerer = auth()->user()->can(\App\Enums\Droit::SitesGerer->value);
    $titre = $i['entites'][$entite];
@endphp

<x-admin.layout :titre="$titre" :fil="[['libelle' => $i['titre']], ['libelle' => $titre]]">

    @if ($gerer)
        {{-- Imports de l'entité : chacun s'ouvre dans une fenêtre (modèle, fichier, envoi). --}}
        <div class="mt-[18px] grid gap-[18px] xl:grid-cols-2">
            @if ($entite === 'sites')
            <section class="bo-carte !mt-0" aria-labelledby="imp-sites">
                <h2 id="imp-sites" class="!text-base">{{ $i['sites_titre'] }}</h2>
                <p class="texte-carte">{{ $i['sites_aide'] }}</p>
                <div class="mt-3 flex flex-wrap gap-3">
                    <x-admin.bouton variante="contour" icone="exporter" href="{{ route('admin.sites.excel.export') }}">{{ __('admin.sites.import.exporter') }}</x-admin.bouton>
                    <x-admin.fenetre id="importer-sites" :titre="$i['sites_titre']" :action="route('admin.sites.excel.deposer')" :bouton="__('admin.sites.import.importer')" fichiers declencheur-icone="importer">
                        <x-slot:declencheur>{{ __('admin.sites.import.importer') }}</x-slot:declencheur>
                        <p class="texte-carte mb-4">{{ $i['sites_aide'] }}</p>
                        <x-admin.depot-fichier nom="fichier_sites" id="fichier-sites" :libelle="__('admin.sites.import.importer')" required />
                        <x-admin.bouton variante="contour" taille="petit" icone="exporter" class="mt-4" href="{{ route('admin.sites.excel.modele') }}">{{ __('admin.sites.import.modele') }}</x-admin.bouton>
                    </x-admin.fenetre>
                </div>
            </section>
            @else
            <section class="bo-carte !mt-0" aria-labelledby="imp-combine">
                <h2 id="imp-combine" class="!text-base">{{ $i['combine_titre'] }}</h2>
                <p class="texte-carte">{{ $i['combine_aide'] }}</p>
                <div class="mt-3">
                    <x-admin.fenetre id="importer-combine" :titre="$i['combine_titre']" :action="route('admin.sites.boutiques-excel.deposer')" :bouton="__('admin.sites.excel.combine_importer')"
                                     fichiers declencheur-icone="importer">
                        <x-slot:declencheur>{{ __('admin.sites.excel.combine_importer') }}</x-slot:declencheur>
                        <p class="texte-carte mb-4">{{ $i['combine_aide'] }}</p>
                        <x-admin.depot-fichier nom="fichier" id="fichier-combine" :libelle="__('admin.sites.excel.combine_importer')" required />
                        <x-admin.bouton variante="contour" taille="petit" icone="exporter" class="mt-4" href="{{ route('admin.sites.boutiques-excel.modele') }}">{{ __('admin.sites.excel.modele') }}</x-admin.bouton>
                    </x-admin.fenetre>
                </div>
            </section>
            @endif
        </div>
    @endif

    {{-- Historique des imports de cette entité --}}
    <section class="bo-carte" aria-labelledby="journal-titre">
        <h2 id="journal-titre" class="!text-[1.15rem]">{{ $i['historique'] }}</h2>
        <x-tableau :t="$tableau" :libelle="$i['historique'].' — '.$titre" class="mt-3" />
    </section>
</x-admin.layout>
