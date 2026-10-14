@props(['page', 'derniere', 'url', 'lien' => null])

{{--
    Pagination « Précédent 1 2 3 … 35 Suivant », navigable au clavier ; la page courante porte aria-current.
    $url : fermeture (int $page) => adresse ; $lien : attribut ajouté aux liens (rechargement partiel d'un tableau).
--}}
@php
    $l = __('admin.donnees');
    $voisines = range(max(1, $page - 1), min($derniere, $page + 1));
    $pages = array_values(array_unique([1, ...$voisines, $derniere]));
    sort($pages);
    $suite = [];
    foreach ($pages as $i => $p) {
        if ($i > 0 && $p - $pages[$i - 1] > 1) {
            $suite[] = $p - $pages[$i - 1] === 2 ? $p - 1 : null;
        }
        $suite[] = $p;
    }
@endphp
@if ($derniere > 1)
    <nav {{ $attributes->merge(['class' => 'adm-pagination']) }} aria-label="{{ $l['pagination'] }}">
        <ul class="bo-pagination">
            <li>
                @if ($page > 1)
                    <a href="{{ $url($page - 1) }}" {{ $lien }} rel="prev">{{ $l['precedent'] }}</a>
                @else
                    <span aria-disabled="true">{{ $l['precedent'] }}</span>
                @endif
            </li>
            @foreach ($suite as $p)
                <li>
                    @if ($p === null)
                        <span aria-hidden="true">…</span>
                    @else
                        <a href="{{ $url($p) }}" {{ $lien }}{!! $p === $page ? ' aria-current="page"' : '' !!} aria-label="{{ __('admin.donnees.page', ['n' => $p]) }}">{{ $p }}</a>
                    @endif
                </li>
            @endforeach
            <li>
                @if ($page < $derniere)
                    <a href="{{ $url($page + 1) }}" {{ $lien }} rel="next">{{ $l['suivant'] }}</a>
                @else
                    <span aria-disabled="true">{{ $l['suivant'] }}</span>
                @endif
            </li>
        </ul>
    </nav>
@endif
