@php
    $i = __('admin.imports');
    $gerer = auth()->user()->can(\App\Enums\Droit::SitesGerer->value);
@endphp

<x-admin.layout :titre="$i['titre']" :fil="[['libelle' => $i['titre']]]">
    <div class="bo-carte !mt-0">
        <p class="texte-carte max-w-3xl">{{ $i['intro'] }}</p>
    </div>

    @if ($gerer)
        <div class="mt-[18px] grid gap-[18px] xl:grid-cols-3">
            {{-- Sites et capacités prévues --}}
            <section class="bo-carte !mt-0" aria-labelledby="imp-sites">
                <h2 id="imp-sites" class="!text-base">{{ $i['sites_titre'] }}</h2>
                <p class="texte-carte">{{ $i['sites_aide'] }}</p>
                <x-admin.bouton variante="contour" class="mt-3" href="{{ route('admin.sites.excel.modele') }}">{{ __('admin.sites.import.modele') }}</x-admin.bouton>
                <x-admin.bouton variante="contour" class="mt-3" href="{{ route('admin.sites.excel.export') }}">{{ __('admin.sites.import.exporter') }}</x-admin.bouton>
                <form method="POST" action="{{ route('admin.sites.excel.deposer') }}" enctype="multipart/form-data" class="mt-3 space-y-3" novalidate>
                    @csrf
                    <x-admin.depot-fichier nom="fichier_sites" id="fichier-sites" :libelle="__('admin.sites.import.importer')" required />
                    @error('fichier_sites') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
                    <x-admin.bouton variante="principal" type="submit">{{ __('admin.sites.import.importer') }}</x-admin.bouton>
                </form>
            </section>

            {{-- Boutiques d'un site --}}
            <section class="bo-carte !mt-0" aria-labelledby="imp-site">
                <h2 id="imp-site" class="!text-base">{{ $i['boutiques_site_titre'] }}</h2>
                <p class="texte-carte">{{ $i['boutiques_site_aide'] }}</p>
                @if ($sitesModifiables->isEmpty())
                    <p class="mt-3">{{ $i['aucun_site_modifiable'] }}</p>
                @else
                    <form method="POST" action="{{ route('admin.referencement.imports.boutiques-site') }}" enctype="multipart/form-data" class="mt-3 space-y-3" novalidate>
                        @csrf
                        <div class="formulaire-champ !mt-0">
                            <label for="site-import">{{ ucfirst($i['site']) }}</label>
                            <select id="site-import" name="site_id" class="champ" required>
                                <option value="">{{ $i['choisir_site'] }}</option>
                                @foreach ($sitesModifiables as $s)<option value="{{ $s->id }}">{{ $s->code }} · {{ $s->nom }}</option>@endforeach
                            </select>
                        </div>
                        <x-admin.depot-fichier nom="fichier" id="fichier-site" :libelle="__('admin.sites.excel.importer')" required />
                        @error('site_id') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
                        <x-admin.bouton variante="principal" type="submit">{{ __('admin.sites.excel.importer') }}</x-admin.bouton>
                    </form>
                @endif
            </section>

            {{-- Boutiques de plusieurs sites --}}
            <section class="bo-carte !mt-0" aria-labelledby="imp-combine">
                <h2 id="imp-combine" class="!text-base">{{ $i['combine_titre'] }}</h2>
                <p class="texte-carte">{{ $i['combine_aide'] }}</p>
                <x-admin.bouton variante="contour" class="mt-3" href="{{ route('admin.sites.boutiques-excel.modele') }}">{{ __('admin.sites.excel.modele') }}</x-admin.bouton>
                <form method="POST" action="{{ route('admin.sites.boutiques-excel.deposer') }}" enctype="multipart/form-data" class="mt-3 space-y-3" novalidate>
                    @csrf
                    <x-admin.depot-fichier nom="fichier" id="fichier-combine" :libelle="__('admin.sites.excel.combine_importer')" required />
                    @error('fichier') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
                    <x-admin.bouton variante="principal" type="submit">{{ __('admin.sites.excel.combine_importer') }}</x-admin.bouton>
                </form>
            </section>
        </div>
    @endif

    {{-- Journal de tous les imports --}}
    <section class="bo-carte" aria-labelledby="journal-titre">
        <h2 id="journal-titre" class="!text-[1.15rem]">{{ $i['journal_titre'] }}</h2>
        <x-tableau :t="$tableau" :libelle="$i['journal_titre']" class="mt-3" />
    </section>
</x-admin.layout>
