{{--
    Coordonnées d'assistance, d'accueil physique et contacts spécialisés (paramètres de la plateforme).
    Un champ vide n'est pas affiché.
--}}
@php
    use App\Support\Telephone;

    $c = plateforme('contacts');
    $siege = plateforme('siege');
    $specialises = array_filter(plateforme('contacts_specialises'), fn ($s) => $s['afficher'] && ($s['telephone'] || $s['email']));
    $horaires = format_horaires($c['horairesCentreAppels']);
    $horairesAccueil = format_horaires($siege['horairesAccueil']);
    $lignes = array_filter([
        ['horloge', __('public.faq.contact.horaires'), $horaires.($c['messageHorsHoraires'] && $horaires ? ' — '.$c['messageHorsHoraires'] : ($horaires ? '' : $c['messageHorsHoraires'])), null],
        ['telephone', __('public.contact.international'), $c['telephoneInternational'], $c['telephoneInternational'] ? 'tel:'.$c['telephoneInternational'] : null],
        ['telephone', __('public.contact.whatsapp'), $c['whatsapp'], $c['whatsapp'] ? 'https://wa.me/'.ltrim($c['whatsapp'], '+') : null],
        ['courriel', __('public.faq.contact.courriel'), $c['emailAssistance'], $c['emailAssistance'] ? 'mailto:'.$c['emailAssistance'] : null],
    ], fn ($l) => filled($l[2]));
    $adresse = array_filter([$siege['adresseSiege'], $siege['boitePostale']]);
@endphp

@if ($c['numeroVert'])
    <div class="mt-4 flex items-start gap-3 rounded-charte bg-vert-pale p-4">
        <x-public.icone nom="telephone" class="mt-0.5 h-5 w-5 text-vert-fonce" />
        <div>
            <p class="texte-carte">{{ __('public.communs.numero_vert') }}</p>
            <p class="text-lg font-extrabold text-vert-fonce"><a href="tel:{{ $c['numeroVert'] }}" class="inline-flex min-h-[44px] items-center no-underline">{{ Telephone::formaterLocal($c['numeroVert']) }}</a></p>
        </div>
    </div>
@endif

@if ($lignes)
    <dl class="mt-4 space-y-3 text-[0.95rem]">
        @foreach ($lignes as [$icone, $libelle, $valeur, $lien])
            <div class="flex items-start gap-3">
                <x-public.icone :nom="$icone" class="mt-0.5 h-5 w-5 text-gris" />
                <div>
                    <dt class="font-semibold text-encre">{{ $libelle }}</dt>
                    <dd class="break-all">@if ($lien)<a href="{{ $lien }}">{{ $valeur }}</a>@else{{ $valeur }}@endif</dd>
                </div>
            </div>
        @endforeach
    </dl>
@endif

@if ($adresse || $horairesAccueil || $siege['lienCarte'])
    <div class="mt-4 border-t border-ligne pt-4 text-[0.95rem]">
        <p class="font-semibold text-encre">{{ __('public.contact.siege') }}</p>
        @foreach ($adresse as $ligne)
            <p class="whitespace-pre-line">{{ $ligne }}</p>
        @endforeach
        @if ($horairesAccueil)
            <p class="texte-carte">{{ __('public.contact.accueil', ['horaires' => $horairesAccueil]) }}</p>
        @endif
        @if ($siege['lienCarte'])
            <a href="{{ $siege['lienCarte'] }}" rel="noopener" target="_blank">{{ __('public.contact.carte') }}</a>
        @endif
    </div>
@endif

@if ($specialises)
    <div class="mt-4 border-t border-ligne pt-4 text-[0.95rem]">
        @foreach ($specialises as $s)
            <p class="mt-2 font-semibold text-encre">{{ $s['libelle'] ?: $s['nom'] }}</p>
            @if ($s['libelle'] && $s['nom'])<p class="texte-carte">{{ $s['nom'] }}</p>@endif
            @if ($s['telephone'])<p><a href="tel:{{ $s['telephone'] }}">{{ $s['telephone'] }}</a></p>@endif
            @if ($s['email'])<p class="break-all"><a href="mailto:{{ $s['email'] }}">{{ $s['email'] }}</a></p>@endif
        @endforeach
    </div>
@endif
