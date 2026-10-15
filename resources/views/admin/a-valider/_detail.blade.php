{{--
    Panneau de détail d'un élément de la file « À valider » : informations, différences avec la dernière version
    confirmée (avant → après, champs modifiés surlignés), pièces jointes, historique ; Valider, Rejeter (motif
    obligatoire), Ouvrir la fiche complète. Après une décision, l'élément suivant s'ouvre.
--}}
@php
    $v = __('admin.a_valider');
    $e = $d['element'];
@endphp

<dialog x-ref="detail" class="adm-panneau-detail" aria-labelledby="detail-titre" data-detail="{{ $e['cle'] }}">
    <header class="flex items-start justify-between gap-3">
        <div>
            <p class="texte-carte">{{ $v['elements'][$e['element']] }} · {{ $v['detail']['soumis'] }} {{ $e['par'] ?? '' }}, {{ $e['date']?->format('d/m/Y H:i') }}</p>
            <h2 id="detail-titre" class="!text-[1.2rem]">{{ $e['libelle'] }}</h2>
        </div>
        <a href="{{ route('admin.a-valider') }}" class="bo-bouton-icone" aria-label="{{ $v['detail']['fermer'] }}" title="{{ $v['detail']['fermer'] }}"><x-icon name="fermer" class="h-5 w-5" /></a>
    </header>

    <section class="mt-4" aria-labelledby="detail-infos">
        <h3 id="detail-infos" class="!text-base">{{ $v['detail']['informations'] }}</h3>
        <dl class="mt-2 grid gap-x-6 gap-y-2 sm:grid-cols-2">
            @foreach ($d['infos'] as $libelle => $valeur)
                <div><dt class="texte-carte">{{ $libelle }}</dt><dd class="font-semibold">{{ $valeur ?? '' }}</dd></div>
            @endforeach
        </dl>
    </section>

    <section class="mt-5" aria-labelledby="detail-differences" data-differences>
        <h3 id="detail-differences" class="!text-base">{{ $v['detail']['differences'] }}</h3>
        <x-admin.differentiel :differentiel="$d['differentiel']" :libelles="$d['libelles']" :premiere="$d['premiere']" />
    </section>

    <section class="mt-5" aria-labelledby="detail-pieces">
        <h3 id="detail-pieces" class="!text-base">{{ $v['detail']['pieces'] }}</h3>
        @forelse ($d['pieces'] as $piece)
            <p class="mt-1">@if ($piece['url'])<a href="{{ $piece['url'] }}" class="lien">{{ $piece['libelle'] }}</a>@else{{ $piece['libelle'] }}@endif</p>
        @empty
            <p class="texte-carte mt-1">{{ $v['detail']['aucune_piece'] }}</p>
        @endforelse
    </section>

    <section class="mt-5" aria-labelledby="detail-historique">
        <h3 id="detail-historique" class="!text-base">{{ $v['detail']['historique'] }}</h3>
        @if ($d['versions']->isEmpty() && $d['decisions']->isEmpty())
            <p class="texte-carte mt-1">{{ $v['detail']['aucun_historique'] }}</p>
        @else
            <ul class="mt-2 space-y-1">
                @foreach ($d['decisions'] as $decision)
                    <li><x-admin.badge-statut :statut="$decision->decision" /> {{ __('admin.a_valider.detail.version', ['n' => $decision->version]) }}, {{ $decision->created_at?->format('d/m/Y H:i') }}, {{ $decision->decideur?->name }}@if ($decision->motif) : « {{ $decision->motif }} »@endif</li>
                @endforeach
                @foreach ($d['versions'] as $version)
                    <li class="texte-carte">{{ __('admin.a_valider.detail.version', ['n' => $version->numero]) }}, {{ $version->created_at?->format('d/m/Y H:i') }}, {{ $version->auteur?->name }}@if ($version->motif) : {{ $version->motif }}@endif</li>
                @endforeach
            </ul>
        @endif
    </section>

    <footer class="mt-6 flex flex-wrap gap-3 border-t border-ligne pt-4" x-data="{ rejet: false }">
        @if ($e['moi'])
            <x-admin.message type="avertissement" class="!mb-0 w-full" data-motif-moi>{{ $v['erreurs']['auto_validation'] }}</x-admin.message>
        @else
            <form method="POST" action="{{ route('admin.a-valider.decider') }}" class="flex flex-wrap items-start gap-3">
                @csrf
                <input type="hidden" name="mode" value="ligne">
                <input type="hidden" name="elements[]" value="{{ $e['cle'] }}">
                @if ($suivant)<input type="hidden" name="suivant" value="{{ $suivant }}">@endif
                <div x-show="rejet" x-cloak class="w-full">
                    <label for="motif-detail" class="block font-semibold">{{ $v['rejeter_ligne'] }}</label>
                    <textarea id="motif-detail" name="motif" rows="3" minlength="5" maxlength="1000" class="champ mt-1" x-bind:required="rejet"></textarea>
                </div>
                <x-admin.bouton variante="principal" type="submit" name="decision" value="confirmer" x-show="! rejet">{{ $v['detail']['valider'] }}</x-admin.bouton>
                <x-admin.bouton variante="contour" type="button" x-show="! rejet" x-on:click="rejet = true; $nextTick(() => document.getElementById('motif-detail').focus())">{{ $v['detail']['rejeter'] }}</x-admin.bouton>
                <x-admin.bouton variante="danger" type="submit" name="decision" value="rejeter" x-show="rejet" x-cloak>{{ $v['detail']['confirmer_rejet'] }}</x-admin.bouton>
            </form>
        @endif
        <x-admin.bouton variante="contour" href="{{ $e['url'] }}">{{ $v['detail']['fiche'] }}</x-admin.bouton>
    </footer>
</dialog>
