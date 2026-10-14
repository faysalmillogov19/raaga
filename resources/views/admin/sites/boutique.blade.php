@php
    $b = __('admin.sites.boutiques');
    $titre = __('admin.sites.boutiques.fiche_titre', ['code' => $boutique->reference()]);
    $numeroMax = str_repeat('9', \App\Models\Boutique::CHIFFRES_NUMERO);
@endphp

<x-admin.layout :titre="$titre" :fil="[['libelle' => __('admin.sites.titre'), 'url' => route('admin.sites')], ['libelle' => $site->nom, 'url' => route('admin.sites.show', $site)], ['libelle' => $boutique->reference()]]">
    <form method="POST" action="{{ route('admin.sites.boutiques.update', [$site, $boutique]) }}" class="bo-carte !mt-0 space-y-4" novalidate>
        @csrf @method('PUT')

        @if ($boutique->code)
            <p class="texte-carte">{{ __('admin.sites.boutiques.elements_figes', ['code' => $boutique->code]) }}</p>
            <dl class="grid gap-3 sm:grid-cols-3">
                <div><dt class="texte-carte">{{ $b['niveau'] }}</dt><dd class="font-semibold">{{ $boutique->libelleNiveau() }}</dd></div>
                <div><dt class="texte-carte">{{ $b['bloc'] }}</dt><dd class="font-mono font-semibold">{{ $boutique->bloc }}</dd></div>
                <div><dt class="texte-carte">{{ $b['numero'] }}</dt><dd class="font-mono font-semibold">{{ $boutique->numero }}</dd></div>
            </dl>
        @else
            <p class="texte-carte">{{ __('admin.sites.boutiques.code_a_venir', ['site' => $site->code ?? '…']) }}</p>
            <div class="grid gap-4 lg:grid-cols-3">
                @include('admin.sites._champs-code', ['prefixe' => 'fiche', 'boutique' => $boutique])
                <x-admin.champ nom="numero" :libelle="$b['numero']" type="number" min="1" max="{{ $numeroMax }}" required
                               :valeur="preg_match('/(\d+)$/', (string) $boutique->numero, $m) ? (int) $m[1] : null" :aide="$b['numero_aide']" />
            </div>
        @endif

        <div class="grid gap-4 lg:grid-cols-3">
            @include('admin.sites._champs-fiche', ['prefixe' => 'fiche', 'boutique' => $boutique])
        </div>

        <div class="flex gap-3">
            <button type="submit" class="bo-bouton bo-bouton-principal">{{ $b['enregistrer'] }}</button>
            <a href="{{ route('admin.sites.show', $site) }}" class="bo-bouton bo-bouton-secondaire">{{ __('admin.tranches.formulaire.annuler') }}</a>
        </div>
    </form>
</x-admin.layout>
