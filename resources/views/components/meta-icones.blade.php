{{--
    Icônes du navigateur et de l'application, à partir du symbole simplifié du logo Raaga
    (fichier déposé dans les paramètres de la plateforme, sinon les icônes générées dans public/).
--}}
@php($deposeFavicon = \App\Support\ImagesPlateforme::url('logoSymboleSimplifie'))
<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
<link rel="icon" type="{{ \App\Support\ImagesPlateforme::type('logoSymboleSimplifie') }}" href="{{ $deposeFavicon }}">
<link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
<link rel="manifest" href="{{ route('manifeste') }}">
<meta name="theme-color" content="#005C2B">
