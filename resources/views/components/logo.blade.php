@props(['variante' => 'horizontal', 'alt' => null])

{{--
    Logo Raaga lu dans les paramètres de la plateforme (fichiers de public/images/logo par défaut).
    Variantes : horizontal (fond clair), blanc (fond vert foncé), symbole. Jamais déformé ni recoloré :
    seule la hauteur est fixée par la classe, la largeur suit (w-auto).
    « blanc » sans fichier fourni : le logo couleur est posé sur un fond blanc, avec sa zone de protection.
--}}
@php
    use App\Support\ImagesPlateforme;

    $champ = ['horizontal' => 'logoHorizontal', 'blanc' => 'logoHorizontalBlanc', 'symbole' => 'logoSymbole'][$variante];
    $surFondBlanc = $variante === 'blanc' && ! ImagesPlateforme::disponible($champ);
    if ($surFondBlanc) {
        $champ = 'logoHorizontal';
    }
    $alt ??= __('public.site.logo_alt');
@endphp

@if ($surFondBlanc)
    <span {{ $attributes->only('class')->class(['inline-flex rounded-[6px] bg-white px-2 py-1.5']) }}>
        <img src="{{ ImagesPlateforme::url($champ) }}" @if ($s = ImagesPlateforme::srcset($champ)) srcset="{{ $s }}" @endif alt="{{ $alt }}" class="h-full w-auto">
    </span>
@else
    <img src="{{ ImagesPlateforme::url($champ) }}" @if ($s = ImagesPlateforme::srcset($champ)) srcset="{{ $s }}" @endif alt="{{ $alt }}"
         {{ $attributes->class(['w-auto']) }}>
@endif
