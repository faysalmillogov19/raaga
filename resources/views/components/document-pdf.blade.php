@props(['titre', 'pied' => null])
{{-- Gabarit des documents PDF (dompdf) : styles en ligne, police DejaVu Sans (accents). --}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $titre }}</title>
    <style>
        @page { margin: 28mm 20mm 22mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5pt; color: #2E3A33; line-height: 1.45; }
        h1 { font-size: 16pt; color: #005C2B; margin: 6px 0 4px; }
        .surtitre { font-size: 8.5pt; letter-spacing: .08em; text-transform: uppercase; color: #00843D; font-weight: bold; }
        table.donnees { width: 100%; border-collapse: collapse; margin: 12px 0; }
        table.donnees th, table.donnees td { border-bottom: 1px solid #D9E0DB; padding: 6px 4px; text-align: left; vertical-align: top; }
        table.donnees th { width: 42%; color: #5E6B63; font-weight: normal; }
        .encadre { border: 1.5px solid #00843D; background: #E8F4EC; padding: 10px 12px; margin: 14px 0; }
        .pied { position: fixed; bottom: -12mm; left: 0; right: 0; font-size: 7.5pt; color: #5E6B63; border-top: 1px solid #D9E0DB; padding-top: 4px; }
        .tricolore { height: 4px; background: #CE1126; margin-bottom: 10px; }
        .tricolore span { display: block; height: 4px; width: 50%; margin-left: 50%; background: #00843D; }
    </style>
</head>
<body>
    <div class="tricolore"><span></span></div>
    @include('documents._entete', ['pdf' => true])
    {{ $slot }}
    <div class="pied">{{ $pied ?? '' }}</div>
</body>
</html>
