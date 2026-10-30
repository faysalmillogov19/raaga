{{--
    Document autonome rendu par Chrome (RenduCarte) : styles et polices intégrés, aucune ressource distante.
    « png » : faces l'une sous l'autre, mises à l'échelle pour que 85,6 mm fassent 1011 px (300 dpi).
    « pdf » : une page par face, @page 85,6 × 54 mm, sans marge.
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<style>
@foreach ($polices as $graisse => $source)
@font-face { font-family: 'Public Sans Carte'; src: url('{{ $source }}') format('woff2'); font-weight: {{ $graisse }}; }
@endforeach
{!! preg_replace('/@font-face\s*\{[^}]*\}/', '', $styles) !!}
html, body { margin: 0; padding: 0; background: transparent; }
@if ($mode === 'pdf')
@page { size: 85.6mm 54mm; margin: 0; }
.cr-face { break-after: page; }
.cr-face:last-child { break-after: auto; }
@else
body { zoom: {{ $echelle }}; }
.cr-face { margin-bottom: 4mm; }
@endif
</style>
</head>
<body>
@include('cartes._faces', ['d' => $d])
</body>
</html>
