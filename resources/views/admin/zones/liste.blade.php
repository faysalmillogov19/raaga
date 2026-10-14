@php
    use App\Enums\Droit;

    $z = __('admin.zones');
    $niveaux = $liste === 'niveaux';
    $gerer = auth()->user()->can(Droit::SitesGerer->value);
    $titre = $z[$liste.'_titre'];
@endphp

<x-admin.layout :titre="$titre" :fil="[['libelle' => $titre]]">
    <div class="bo-carte !mt-0">
        <p class="texte-carte max-w-3xl">{{ $z[$liste.'_aide'] }} {{ $z['regles_communes'] }}</p>

        @if ($gerer)
            <details class="mt-4" @if ($errors->any()) open @endif>
                <summary class="bo-bouton-icone bo-bouton-icone-principal" aria-label="{{ $z[$liste.'_ajouter'] }}" data-infobulle="{{ $z[$liste.'_ajouter'] }}">
                    <x-icon name="plus" class="h-[18px] w-[18px]" />
                </summary>
                <form method="POST" action="{{ route('admin.referencement.zones.store', $liste) }}" class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-4" novalidate>
                    @csrf
                    <x-admin.champ nom="code" :libelle="$z['colonnes']['code']" :maxlength="$niveaux ? 3 : 5" required autocomplete="off" :aide="$z[$liste.'_code_aide']" />
                    <x-admin.champ nom="libelle" :libelle="$z['colonnes']['libelle']" maxlength="100" required />
                    @if ($niveaux)
                        <x-admin.champ nom="rang" :libelle="$z['colonnes']['rang']" type="number" min="-9" max="99" required :aide="$z['rang_aide']" />
                    @else
                        <x-admin.champ nom="description" :libelle="$z['colonnes']['description']" maxlength="300" />
                        <x-admin.champ nom="ordre" :libelle="$z['colonnes']['ordre']" type="number" min="0" max="9999" />
                    @endif
                    <div class="flex items-end pb-1"><x-admin.bouton-icone type="submit" icone="check" variante="principal" :intitule="$z[$liste.'_ajouter']" :infobulle="$z['ajouter']" /></div>
                </form>
            </details>
        @endif

        <x-tableau :t="$tableau" :libelle="$titre" class="mt-4" />
    </div>
</x-admin.layout>
