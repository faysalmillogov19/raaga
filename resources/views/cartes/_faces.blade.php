{{--
    Recto et verso de la carte d'identification (maquette du comité restreint du 03/11/2026), en mm. Variables : $d
    (RenduCarte::donnees ou ::specimen). Toutes les images sont intégrées (data URI) : aucune adresse, rendu identique dans
    Chrome et à l'écran. Recto : armoiries, BURKINA FASO, CARTE D'IDENTIFICATION + type, QR code avec logo (jeton de
    vérification seulement, jamais l'IFU), n° de carte (IFU du commerçant), nom et prénom(s), sexe, date et lieu de
    naissance, secteur d'activité, site(s), établie le, expire le, photo. Verso : groupe sanguin (« Non renseigné » sans
    consentement), lieu de résidence, personne à prévenir, consignes, code-barres (IFU et expiration MMAA) ; organisme
    émetteur, logo, mention de carte électronique et code de contrôle, à la place de l'autorité, de la signature, du
    cachet et du numéro vert. Aucun bandeau tricolore.
--}}
@php
    use App\Services\Cartes\Code128;
    use App\Services\Cartes\RenduCarte;

    $rendu = app(RenduCarte::class);
    $modules = $d['qr']->getWidth();
    // Symbole de 18 mm (hors marge blanche d'un module de chaque côté), logo sur 22 % de sa largeur.
    $qrMm = RenduCarte::QR_MM * ($modules + 2) / $modules;
    $logoMm = RenduCarte::QR_MM * RenduCarte::QR_LOGO;
    // Frise : le contenu de frise.svg (tuile de 28 × 22) répété tuile par tuile. Pas de motif <pattern> : Chrome le
    // pixelise à l'impression PDF, alors que des copies de la tuile restent vectorielles.
    $tuile = preg_replace('#^.*?<svg[^>]*>|</svg>\s*$#s', '', (string) file_get_contents(public_path('images/carte/frise.svg')));
    $frise = fn (string $id) => '<svg class="cr-frise" aria-hidden="true" viewBox="0 0 856 22" preserveAspectRatio="none"><defs><g id="'.$id.'">'.$tuile.'</g></defs>'
        .implode('', array_map(fn (int $x) => '<use href="#'.$id.'" x="'.$x.'"/>', range(0, 840, 28))).'</svg>';
    $microtexte = str_repeat(__('carte.microtexte'), 16);
    // Valeur absente : signalée en rouge dans l'aperçu du back-office, « Non renseigné » sur une carte réelle.
    $manque = fn (?string $v) => filled($v) ? e($v) : ($d['specimen'] ? '<span class="cr-manque">'.e(__('admin.plateforme.carte.non_renseigne')).'</span>' : e(__('carte.non_renseigne')));
@endphp

<div class="cr-face" data-face="recto" role="img" aria-label="{{ __('carte.recto') }}">
    <svg class="cr-decor" aria-hidden="true" viewBox="0 0 856 540" preserveAspectRatio="none">
        <path d="M 565 0 C 668 97, 753 162, 856 194 L 856 0 Z" fill="#F3EAD3"/>
        <path d="M 565 0 C 668 97, 753 162, 856 194" fill="none" stroke="#B8902F" stroke-width="4"/>
        <path d="M 616 0 C 702 70, 770 119, 856 140" fill="none" stroke="#D9BC6E" stroke-width="1.2"/>
        <circle cx="813" cy="594" r="200" fill="none" stroke="#F1E6CB" stroke-width="46"/>
        <circle cx="813" cy="594" r="150" fill="none" stroke="#B8902F" stroke-width="3"/>
        <circle cx="813" cy="594" r="175" fill="none" stroke="#D9BC6E" stroke-width="1.2"/>
        <circle cx="813" cy="594" r="225" fill="none" stroke="#B8902F" stroke-width="1.5"/>
        <circle cx="813" cy="594" r="110" fill="none" stroke="#EADBB4" stroke-width="20"/>
    </svg>
    {{-- Filigranes (facultatifs, déjà en or à l'opacité de leur rang) : principal au centre, secondaire en bas à gauche. --}}
    @if ($d['filigranes']['filigranePrincipalRecto'] ?? null)<img class="cr-filigrane cr-filigrane-recto-principal" src="{{ $d['filigranes']['filigranePrincipalRecto'] }}" alt="" aria-hidden="true">@endif
    @if ($d['filigranes']['filigraneSecondaireRecto'] ?? null)<img class="cr-filigrane cr-filigrane-recto-secondaire" src="{{ $d['filigranes']['filigraneSecondaireRecto'] }}" alt="" aria-hidden="true">@endif

    <div class="cr-entete">
        @if ($d['armoiries'])<img class="cr-entete-armoiries" src="{{ $d['armoiries'] }}" alt="">@endif
        <div>
            <p class="cr-pays">{{ __('carte.pays') }}</p>
            <p class="cr-titre">{{ __('carte.titre') }} <span>{{ $d['type'] }}</span></p>
        </div>
    </div>

    <div class="cr-qr">
        <div style="width: {{ round($qrMm, 3) }}mm; height: {{ round($qrMm, 3) }}mm">{!! $rendu->svgQr($d['qr'], __('carte.qr'), '#1E1A14', 1) !!}</div>
        @if ($d['logo'])
            <div class="cr-qr-logo" style="width: {{ round($logoMm, 3) }}mm; height: {{ round($logoMm, 3) }}mm"><img src="{{ $d['logo'] }}" alt=""></div>
        @endif
    </div>

    <div class="cr-infos">
        <div>
            <p class="cr-libelle">{{ $d['commercant'] ? __('carte.numero_ifu') : __('carte.numero') }}</p>
            <p class="cr-numero" data-champ="numero">{{ $d['numero'] }}</p>
        </div>
        <div>
            <p class="cr-libelle">{{ $d['representant'] ? __('carte.raison_sociale') : __('carte.titulaire') }}</p>
            <p class="cr-titulaire" data-champ="titulaire">{{ $d['titulaire'] }}</p>
            @if ($d['representant'])<p class="cr-representant">{{ __('carte.representant', ['nom' => $d['representant']]) }}</p>@endif
        </div>
        <div class="cr-grille {{ $d['commercant'] ? 'cr-grille-3' : '' }}">
            @if ($d['commercant'])
                <div><p class="cr-libelle">{{ __('carte.sexe') }}</p><p class="cr-valeur" data-champ="sexe">{!! $manque($d['sexe']) !!}</p></div>
            @endif
            <div><p class="cr-libelle">{{ __('carte.ne_le') }}</p><p class="cr-valeur" data-champ="ne_le">{!! $manque($d['ne_le']) !!}</p></div>
            @if ($d['commercant'])
                <div><p class="cr-libelle">{{ __('carte.lieu_naissance') }}</p><p class="cr-valeur" data-champ="lieu_naissance">{!! $manque($d['lieu_naissance']) !!}</p></div>
            @endif
        </div>
        @if ($d['commercant'])
            <div class="cr-grille">
                <div><p class="cr-libelle">{{ __('carte.secteur') }}</p><p class="cr-valeur cr-valeur-long" data-champ="secteur">{!! $manque($d['secteur']) !!}</p></div>
                <div><p class="cr-libelle">{{ str_contains((string) $d['sites'], ' · ') ? __('carte.sites') : __('carte.site') }}</p><p class="cr-valeur cr-valeur-long" data-champ="sites">{!! $manque($d['sites']) !!}</p></div>
            </div>
        @endif
        <div class="cr-grille">
            <div><p class="cr-libelle">{{ __('carte.etablie') }}</p><p class="cr-valeur" data-champ="etablie">{!! $manque($d['etablie']) !!}</p></div>
            <div><p class="cr-libelle">{{ __('carte.expire') }}</p><p class="cr-valeur" data-champ="expire">{{ $d['expire'] }}</p></div>
        </div>
    </div>

    <div class="cr-photo">
        @if ($d['photo'])<img src="{{ $d['photo'] }}" alt="{{ __('carte.photo') }}">@else{{ __('carte.photo') }}@endif
    </div>

    {!! $frise('cr-frise-recto') !!}
    <div class="cr-microtexte" aria-hidden="true">{{ $microtexte }}</div>
    @if ($d['specimen'])<span class="cr-specimen" aria-hidden="true">{{ __('carte.specimen') }}</span>@endif
</div>

<div class="cr-face" data-face="verso" role="img" aria-label="{{ __('carte.verso') }}">
    {{-- Filigranes (facultatifs) : principal derrière la colonne de gauche, secondaire en bas à droite sous le cachet. --}}
    @if ($d['filigranes']['filigranePrincipalVerso'] ?? null)<img class="cr-filigrane cr-filigrane-verso-principal" src="{{ $d['filigranes']['filigranePrincipalVerso'] }}" alt="" aria-hidden="true">@endif
    @if ($d['filigranes']['filigraneSecondaireVerso'] ?? null)<img class="cr-filigrane cr-filigrane-verso-secondaire" src="{{ $d['filigranes']['filigraneSecondaireVerso'] }}" alt="" aria-hidden="true">@endif

    <div class="cr-bandeau">
        <span class="cr-bandeau-titre">{{ __('carte.carte_raaga') }}</span>
        <span class="cr-bandeau-numero">{{ __('carte.numero_court', ['numero' => $d['numero']]) }}</span>
    </div>

    <div class="cr-corps">
        <div class="cr-gauche">
            <div class="cr-mentions">
                @if ($d['commercant'])
                    <div class="cr-grille">
                        <div><p class="cr-libelle">{{ __('carte.groupe_sanguin') }}</p><p class="cr-valeur" data-champ="groupe_sanguin">{{ $d['groupe_sanguin'] ?? __('carte.non_renseigne') }}</p></div>
                        <div><p class="cr-libelle">{{ __('carte.residence') }}</p><p class="cr-valeur" data-champ="residence">{!! $manque($d['residence']) !!}</p></div>
                    </div>
                    <div><p class="cr-libelle">{{ __('carte.personne_prevenir') }}</p><p class="cr-valeur" data-champ="personne_prevenir">{!! $manque($d['personne_prevenir']) !!}</p></div>
                @endif
                <p class="cr-texte">{{ __('carte.personnelle') }} {{ __('carte.perte') }}</p>
            </div>
            <div>
                <div class="cr-code-barres">
                    {!! Code128::svg($d['code'], '#1E1A14', __('carte.code_barres')) !!}
                    <p class="cr-code-texte" data-champ="code">{{ $d['code'] }}</p>
                </div>
                <p class="cr-legende">{{ $d['commercant'] ? __('carte.legende_code_barres_ifu') : __('carte.legende_code_barres') }}</p>
            </div>
        </div>
        <div class="cr-separateur" aria-hidden="true"></div>
        <div class="cr-droite">
            <p class="cr-libelle">{{ __('carte.emise_par') }}</p>
            <p class="cr-emetteur" data-champ="emetteur">{!! $manque($d['emetteur']) !!}</p>
            @if ($d['logo'])<img class="cr-logo-emetteur" src="{{ $d['logo'] }}" alt="">@endif
            <p class="cr-electronique">{{ __('carte.electronique') }}</p>
            <p class="cr-libelle cr-libelle-controle">{{ __('carte.code_controle') }}</p>
            <p class="cr-controle" data-champ="controle">{{ $d['controle'] }}</p>
        </div>
    </div>

    {!! $frise('cr-frise-verso') !!}
    <div class="cr-microtexte" aria-hidden="true">{{ $microtexte }}</div>
    @if ($d['specimen'])<span class="cr-specimen" aria-hidden="true">{{ __('carte.specimen') }}</span>@endif
</div>
