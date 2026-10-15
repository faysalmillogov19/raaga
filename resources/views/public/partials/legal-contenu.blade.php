{{-- Contenu des pages légales, généré depuis les paramètres de la plateforme. Variable : $page. --}}
@php
    use App\Support\Telephone;

    $m = plateforme('mentions');
    $siege = plateforme('siege');
    $l = fn (string $cle) => __('public.legal.mentions.'.$cle);
    $joindre = fn (array $parties, string $sep = ' · ') => implode($sep, array_filter($parties)) ?: null;

    $rubriques = $page !== 'mentions' ? [] : array_filter([
        $l('editeur') => array_filter([
            $l('denomination') => $m['denominationSociale'],
            $l('forme') => $m['formeJuridique'],
            $l('capital') => $m['capitalSocial'] !== null ? format_fcfa($m['capitalSocial']) : null,
            $l('rccm') => $m['rccm'],
            $l('ifu') => $m['ifu'],
            $l('siege') => $joindre([$siege['adresseSiege'], $siege['boitePostale']], ', '),
            $l('contact') => $joindre([
                plateforme('contacts.numeroVert') ? Telephone::formaterLocal(plateforme('contacts.numeroVert')) : null,
                plateforme('contacts.emailAssistance'),
            ], ' · '),
        ]),
        $l('directeur') => array_filter(['' => $joindre([$m['directeurPublication']['nom'], $m['directeurPublication']['fonction']], ', ')]),
        $l('hebergement') => array_filter(['' => $joindre([$m['hebergeur']['nom'], $m['hebergeur']['adresse'], $m['hebergeur']['contact']])]),
        $l('cil') => array_filter(['' => $m['referenceCil']]),
        $l('autorite') => array_filter(['' => $m['referenceAutoriteMarche']]),
    ]);

    $donnees = plateforme('contacts_specialises.contactDonneesPersonnelles');
    $contactDonnees = $donnees['afficher'] ? $joindre([$donnees['nom'], $donnees['telephone'], $donnees['email']], ' · ') : null;
@endphp

@if ($rubriques)
    @foreach ($rubriques as $rubrique => $lignes)
        <section class="mb-6">
            <h2 class="!text-xl">{{ $rubrique }}</h2>
            <dl class="mt-2 space-y-1">
                @foreach ($lignes as $libelle => $valeur)
                    <div>@if ($libelle !== '')<dt class="inline font-semibold text-encre">{{ $libelle }} :</dt> @endif<dd class="inline whitespace-pre-line">{{ $valeur }}</dd></div>
                @endforeach
            </dl>
        </section>
    @endforeach
@elseif ($page === 'donnees' && ($contactDonnees || $m['referenceCil']))
    @if ($contactDonnees)
        <h2 class="!text-xl">{{ __('public.legal.donnees.responsable') }}</h2>
        <p class="mt-2">{{ $contactDonnees }}</p>
    @endif
    @if ($m['referenceCil'])
        <p class="mt-4">{{ __('public.legal.donnees.cil', ['reference' => $m['referenceCil']]) }}</p>
    @endif
    <p class="mt-6 text-gris">{{ __('public.legal.en_redaction') }}</p>
@else
    <p class="text-lg leading-relaxed">{{ __('public.legal.en_redaction') }}</p>
@endif
