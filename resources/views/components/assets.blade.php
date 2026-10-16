{{--
    Feuilles de style et scripts, fichiers statiques de public/ (ni npm, ni Vite, ni CDN), versionnés par leur date :
    app.css (Tailwind figé, ne pas modifier), admin.css (back-office), raaga.css (nouveaux styles, chargé en dernier),
    app.js (JavaScript natif) puis Alpine.js 3.17.4, tous deux avec « defer » (app.js déclare les composants à alpine:init).
--}}
@props(['admin' => false, 'scripts' => true])
<link rel="stylesheet" href="{{ asset_versionne('css/app.css') }}">
@if ($admin)
    <link rel="stylesheet" href="{{ asset_versionne('css/admin.css') }}">
@endif
<link rel="stylesheet" href="{{ asset_versionne('css/raaga.css') }}">
@if ($scripts)
    <script src="{{ asset_versionne('js/app.js') }}" defer></script>
    <script src="{{ asset_versionne('vendor/alpinejs/3.17.4/alpine.min.js') }}" defer></script>
@endif
