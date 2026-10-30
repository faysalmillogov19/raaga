{{--
    Recto et verso de la carte d'identification (maquette validée le 24/10/2026), en mm. Variables : $d (RenduCarte::donnees
    ou ::specimen). Toutes les images sont intégrées (data URI) : aucune adresse, rendu identique dans Chrome et à l'écran.
    Recto : armoiries, BURKINA FASO, CARTE D'IDENTIFICATION + type, QR code avec logo, titulaire, né(e) le, carte n°,
    expire le, photo. Aucune information sur la boutique ni sur l'activité. Filigranes : les quatre réglages de la section
    « Carte d'identification », facultatifs ; logo du QR code : le logo unique de l'onglet « Identité ».
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
    $manque = fn (?string $v) => filled($v) ? e($v) : '<span class="cr-manque">'.e(__('admin.plateforme.carte.non_renseigne')).'</span>';
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
            <p class="cr-libelle">{{ __('carte.titulaire') }}</p>
            <p class="cr-titulaire" data-champ="titulaire">{{ $d['titulaire'] }}</p>
            @if ($d['representant'])<p class="cr-representant">{{ __('carte.representant', ['nom' => $d['representant']]) }}</p>@endif
        </div>
        <div class="cr-grille">
            @if ($d['ne_le'])
                <div><p class="cr-libelle">{{ __('carte.ne_le') }}</p><p class="cr-valeur" data-champ="ne_le">{{ $d['ne_le'] }}</p></div>
            @endif
            <div><p class="cr-libelle">{{ __('carte.numero') }}</p><p class="cr-valeur" data-champ="numero">{{ $d['numero'] }}</p></div>
            <div><p class="cr-libelle">{{ __('carte.expire') }}</p><p class="cr-valeur" data-champ="expire">{{ $d['expire'] }}</p></div>
        </div>
    </div>

    <div class="cr-photo">
        @if ($d['photo'])<img src="{{ $d['photo'] }}" alt="{{ __('carte.photo') }}">@else{{ __('carte.photo') }}@endif
    </div>

    {!! $frise('cr-frise-recto') !!}
    <div class="cr-microtexte" aria-hidden="true">{{ $microtexte }}</div>
    <div class="cr-bande" aria-hidden="true"><span></span><span></span><span></span></div>
    @if ($d['specimen'])<span class="cr-specimen" aria-hidden="true">{{ __('carte.specimen') }}</span>@endif
</div>

<div class="cr-face" data-face="verso" role="img" aria-label="{{ __('carte.verso') }}">
    {{-- Filigranes (facultatifs) : principal derrière la colonne de gauche, secondaire en bas à droite sous le cachet. --}}
    @if ($d['filigranes']['filigranePrincipalVerso'] ?? null)<img class="cr-filigrane cr-filigrane-verso-principal" src="{{ $d['filigranes']['filigranePrincipalVerso'] }}" alt="" aria-hidden="true">@endif
    @if ($d['filigranes']['filigraneSecondaireVerso'] ?? null)<img class="cr-filigrane cr-filigrane-verso-secondaire" src="{{ $d['filigranes']['filigraneSecondaireVerso'] }}" alt="" aria-hidden="true">@endif

    <div class="cr-bandeau">
        <span class="cr-bandeau-titre">{{ __('carte.carte_raaga') }}</span>
        <span class="cr-bandeau-numero">{{ $d['numero'] }}</span>
    </div>

    <div class="cr-corps">
        <div class="cr-gauche">
            <div class="cr-mentions">
                <p>{{ __('carte.personnelle') }}</p>
                <p>{{ __('carte.perte') }}</p>
                <p>{{ __('carte.numero_vert') }} <strong data-champ="numero_vert">{!! $manque($d['numero_vert']) !!}</strong></p>
            </div>
            <div>
                <div class="cr-code-barres">
                    {!! Code128::svg($d['code'], '#1E1A14', __('carte.code_barres')) !!}
                    <p class="cr-code-texte" data-champ="code">{{ $d['code'] }}</p>
                </div>
                <p class="cr-legende">{{ __('carte.legende_code_barres') }}</p>
            </div>
        </div>
        <div class="cr-separateur" aria-hidden="true"></div>
        <div class="cr-droite">
            <p class="cr-libelle">{{ __('carte.autorite') }}</p>
            <p class="cr-qualite" data-champ="qualite">{!! $manque($d['qualite']) !!}</p>
            @if ($d['signature'])<img class="cr-signature" src="{{ $d['signature'] }}" alt="{{ __('carte.signature') }}">@else<p class="cr-signature cr-signature-vide cr-manque">{{ __('carte.signature') }}</p>@endif
            <p class="cr-nom-autorite" data-champ="nom_autorite">{!! $manque($d['nom_autorite']) !!}</p>
            @if ($d['cachet'])<img class="cr-cachet" src="{{ $d['cachet'] }}" alt="{{ __('carte.cachet') }}">@else<p class="cr-cachet cr-cachet-vide">{{ __('carte.cachet') }}</p>@endif
        </div>
    </div>

    {!! $frise('cr-frise-verso') !!}
    <div class="cr-microtexte" aria-hidden="true">{{ $microtexte }}</div>
    <div class="cr-bande" aria-hidden="true"><span></span><span></span><span></span></div>
    @if ($d['specimen'])<span class="cr-specimen" aria-hidden="true">{{ __('carte.specimen') }}</span>@endif
</div>
