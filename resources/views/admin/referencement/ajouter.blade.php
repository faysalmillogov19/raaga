@php
    $r = __('admin.referencement.boutiques');
    $b = __('admin.sites.boutiques');
    $numeroMax = str_repeat('9', \App\Models\Boutique::CHIFFRES_NUMERO);
@endphp

<x-admin.layout :titre="$r['ajouter']" :fil="[['libelle' => $r['titre'], 'url' => route('admin.referencement.boutiques')], ['libelle' => $r['ajouter']]]">
    {{-- 1. Choix du site : seuls les sites en brouillon ou en révision, avec un code, sont proposés. --}}
    <section class="bo-carte !mt-0" aria-labelledby="site-titre">
        <h2 id="site-titre" class="!text-[1.15rem]">{{ $r['ajouter'] }}</h2>
        <p class="texte-carte mt-1 max-w-3xl">{{ $r['ajouter_intro'] }}</p>
        @if ($sites->isEmpty())
            <p class="bo-message bo-message-erreur mt-4 !mb-0" role="status">{{ $r['aucun_site_modifiable'] }}</p>
        @else
            <form method="GET" action="{{ route('admin.referencement.boutiques.ajouter') }}" class="mt-3 flex flex-wrap items-end gap-3">
                <div class="formulaire-champ !mt-0">
                    <label for="ajout-site">{{ $r['choisir_site'] }}</label>
                    <select id="ajout-site" name="site" class="champ" required>
                        <option value="">{{ $r['choisir'] }}</option>
                        @foreach ($sites as $s)
                            <option value="{{ $s->id }}" @selected($site?->id === $s->id)>{{ $s->code }} · {{ $s->nom }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="bo-bouton bo-bouton-secondaire">{{ $r['continuer'] }}</button>
            </form>
        @endif
    </section>

    {{-- 2. Une boutique ou une série numérotée, sur le site choisi. --}}
    @if ($site)
        <section class="bo-carte" aria-labelledby="saisie-titre">
            <h2 id="saisie-titre" class="!text-[1.15rem]">{{ __('admin.referencement.boutiques.site_choisi', ['site' => $site->code.' · '.$site->nom]) }}</h2>
            @if ($types->isEmpty())
                <p class="bo-message bo-message-erreur mt-4">{{ __('admin.sites.types.aucun') }} <a href="{{ route('admin.sites.types') }}">{{ __('admin.types.titre') }}</a></p>
            @else
                <div class="mt-4 grid gap-[18px] xl:grid-cols-2">
                    <form method="POST" action="{{ route('admin.referencement.boutiques.serie', $site) }}" class="rounded-charte border border-ligne p-4" novalidate>
                        @csrf
                        <h3 class="!text-base">{{ $b['serie_titre'] }}</h3>
                        <p class="texte-carte">{{ __('admin.sites.boutiques.serie_aide', ['site' => $site->code]) }}</p>
                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            @include('admin.sites._champs-code', ['prefixe' => 'serie', 'boutique' => null])
                            <x-admin.champ nom="debut" :libelle="$b['debut']" type="number" min="1" max="{{ $numeroMax }}" valeur="1" required />
                            <x-admin.champ nom="fin" :libelle="$b['fin']" type="number" min="1" max="{{ $numeroMax }}" required />
                            @include('admin.sites._champs-fiche', ['prefixe' => 'serie', 'boutique' => null])
                        </div>
                        <button type="submit" class="bo-bouton bo-bouton-principal mt-3">{{ $b['generer'] }}</button>
                    </form>

                    <form method="POST" action="{{ route('admin.referencement.boutiques.store', $site) }}" class="rounded-charte border border-ligne p-4" novalidate>
                        @csrf
                        <h3 class="!text-base">{{ $b['ajouter_titre'] }}</h3>
                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            @include('admin.sites._champs-code', ['prefixe' => 'unique', 'boutique' => null])
                            <div class="formulaire-champ !mt-0"><label for="unique-numero">{{ $b['numero'] }}</label>
                                <input id="unique-numero" name="numero" type="number" min="1" max="{{ $numeroMax }}" required class="champ" aria-describedby="unique-numero-aide">
                                <p id="unique-numero-aide" class="aide-champ">{{ $b['numero_aide'] }}</p></div>
                            @include('admin.sites._champs-fiche', ['prefixe' => 'unique', 'boutique' => null])
                        </div>
                        <button type="submit" class="bo-bouton bo-bouton-principal mt-3">{{ $b['ajouter'] }}</button>
                    </form>
                </div>
            @endif
        </section>
    @endif
</x-admin.layout>
