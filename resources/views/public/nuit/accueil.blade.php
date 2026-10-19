{{--
    Accueil, thème « nuit » (maquette docs/maquettes/maquette-accueil-theme-nuit.html). Toutes les données viennent de la
    base (App\Services\Publique\AccueilNuit) ; le téléphone illustré est un exemple (aria-hidden, mention « Exemple »).
    Diapositives actives : le carrousel remplace le bandeau blanc. Carte « Investir » : même logique que le thème clair.
--}}
@php
    use App\Services\Publique\AccueilNuit;
    use App\Support\Diapositives;
    use App\Support\TitreNuit;

    $a = __('public.accueil_raaga');
    $d = __('public.demarches');
    $n = __('public.nuit.accueil');
    $c = __('public.nuit.campagne');
    $totalBoutiques = $niveaux->sum('total');
    [$titreLeger, $titreFort] = TitreNuit::decouper($a['titre'], 2);
    $caution = $durees->isNotEmpty() ? AccueilNuit::mois([$durees->min(fn ($l) => $l['caution'][0]), $durees->max(fn ($l) => $l['caution'][1])]) : null;
    $avance = $durees->isNotEmpty() ? AccueilNuit::mois([$durees->min(fn ($l) => $l['avance'][0]), $durees->max(fn ($l) => $l['avance'][1])]) : null;
    $court = fn (array $niveau) => mb_strlen($niveau['libelle']) <= 4 ? $niveau['libelle'] : $niveau['code'];
    // Surface du type de référence : « 9 » ou « 8,6 » (pas de décimale inutile).
    $surface = $tarifs ? format_nombre($tarifs['surface'], floor($tarifs['surface']) == $tarifs['surface'] ? 0 : 1) : null;
@endphp

<x-public.layout :titre="$a['titre_page']" :sombre="true">
    @if ($premiere = $diapositives->first())
        <x-slot:head>
            <link rel="preload" as="image" type="image/webp" imagesrcset="{{ Diapositives::srcset($premiere['image']) }}" imagesizes="100vw" fetchpriority="high">
        </x-slot:head>
    @endif

    {{-- 1. Diapositives actives : carrousel ; sinon bandeau blanc avec le téléphone illustré. --}}
    @if ($diapositives->isNotEmpty())
        <x-public.carrousel :diapositives="$diapositives" :url-candidater="$urlCandidater" />
    @else
        <section class="nuit-section-blanc nuit-hero" aria-labelledby="accueil-titre">
            <div class="conteneur nuit-hero-corps">
                <div class="nuit-hero-texte">
                    <span class="nuit-badge"><span class="nuit-badge-numero" aria-hidden="true">1</span>{{ $n['badge'] }}</span>
                    <h1 id="accueil-titre" class="nuit-hero-titre">{{ $titreLeger }} <span class="nuit-hero-accent">{{ $titreFort }}</span></h1>
                    <p class="nuit-hero-intro">{{ $a['sous_titre'] }}
                        @if ($totalBoutiques > 0) {{ __('public.nuit.accueil.chiffres', ['boutiques' => format_nombre($totalBoutiques), 'niveaux' => $niveaux->count()]) }}@endif</p>
                    <div class="nuit-boutons">
                        <x-public.pilule :href="$urlCandidater" class="nuit-pilule-grand">{{ $a['candidater'] }}</x-public.pilule>
                        <x-public.pilule variante="contour" href="#comment" class="nuit-pilule-grand">{{ $a['comment'] }}</x-public.pilule>
                    </div>
                </div>
                @if ($exemple)
                    @php
                        $t = $n['telephone'];
                    @endphp
                    <div class="nuit-scene" aria-hidden="true" data-exemple>
                        <span class="nuit-scene-exemple">{{ $n['exemple'] }}</span>
                        <div class="nuit-telephone">
                            <div class="nuit-telephone-ecran">
                                <p class="nuit-telephone-petit">{{ __('public.entete.mon_espace') }}</p>
                                <p class="nuit-telephone-code">{{ $exemple['code'] }}</p>
                                <div class="nuit-telephone-anneau"><span>50 %</span><small>{{ $t['paye'] }}</small></div>
                                <div class="nuit-telephone-grille">
                                    <div><p>{{ $t['loyer'] }}</p><p>{{ format_nombre($exemple['loyer']) }}</p></div>
                                    <div><p>{{ $t['caution'] }}</p><p class="nuit-vif">{{ $t['payee'] }}</p></div>
                                    <div><p>{{ $t['avance'] }}</p><p class="nuit-jaune">{{ $t['echeance'] }}</p></div>
                                    <div><p>{{ $t['carte'] }}</p><p>{{ $t['qr'] }}</p></div>
                                </div>
                            </div>
                        </div>
                        @if ($exemple['moyens'])
                            <div class="nuit-bulle nuit-bulle-moyens">
                                @foreach ($exemple['moyens'] as $moyen)<p><span>{{ $moyen }}</span><span class="nuit-vif">✓</span></p>@endforeach
                            </div>
                        @endif
                        <div class="nuit-bulle nuit-bulle-total">
                            <p class="nuit-bulle-libelle">{{ $t['total'] }}</p>
                            <p class="nuit-bulle-montant">{{ format_fcfa($exemple['total']) }}</p>
                            <div class="nuit-bulle-barres"><span></span><span></span><span></span><span></span><span></span></div>
                        </div>
                        <div class="nuit-bulle nuit-bulle-carte"><p>{{ $t['carte_titre'] }}</p><p>{{ $t['carte_texte'] }}</p></div>
                    </div>
                @endif
            </div>
        </section>
    @endif

    {{-- 2. Qu'est-ce que Raaga ? (orbite) --}}
    <section class="nuit-section nuit-section-principal" aria-labelledby="quoi-titre">
        <div class="conteneur nuit-section-corps nuit-quoi">
            <div class="nuit-orbite" aria-hidden="true">
                <span class="nuit-orbite-anneau"></span>
                <span class="nuit-orbite-centre">{{ \Illuminate\Support\Str::before(plateforme('identite.nomPlateforme'), ' ·') }}</span>
                @foreach (['boutiques', 'pole', 'parkings', 'services'] as $i => $cle)
                    <span class="nuit-orbite-satellite nuit-orbite-{{ $i + 1 }}">{{ $n['orbite'][$cle] }}</span>
                @endforeach
            </div>
            <div class="nuit-quoi-texte">
                <p class="nuit-surtitre">{{ $n['quoi_surtitre'] }}</p>
                <h2 id="quoi-titre" class="nuit-titre">{{ $n['quoi_titre'] }}<br><strong>{{ $n['quoi_fort'] }}</strong></h2>
                <p class="nuit-paragraphe">{{ $sitePrincipal && $totalBoutiques > 0
                    ? __('public.nuit.accueil.quoi_texte', ['site' => $sitePrincipal->nom, 'ville' => $sitePrincipal->localite() ?? '', 'boutiques' => format_nombre($totalBoutiques), 'niveaux' => $niveaux->count()])
                    : $n['quoi_texte_sans_site'] }}</p>
                <p class="nuit-paragraphe">{{ $n['quoi_reservation'] }}</p>
                <x-public.pilule :href="route('boutiques')">{{ $a['sites_voir'] }}</x-public.pilule>
            </div>
        </div>
    </section>

    {{-- 3. Pourquoi Raaga : six atouts. --}}
    <x-public.section id="comment" fond="alterne" :surtitre="$n['pourquoi_surtitre']" :titre="$n['pourquoi_titre']" :fort="$n['pourquoi_fort']" :centre="true">
        <ul class="nuit-atouts">
            @foreach ($n['atouts'] as $i => $atout)
                @php
                    [$couleur, $icone, $texte] = [['orange', null, '1'], ['vert', 'check', null], ['bleu', null, 'ID'], ['rose', null, 'QR'], ['cyan', null, 'PDF'], ['rouge', null, '!']][$i];
                @endphp
                <li class="nuit-carte nuit-atout">
                    <x-public.pastille-icone :couleur="$couleur" :icone="$icone" :texte="$texte" />
                    <h3 class="nuit-atout-titre">{{ $atout['titre'] }}</h3>
                    <p class="nuit-secondaire">{{ $atout['texte'] }}</p>
                </li>
            @endforeach
        </ul>
    </x-public.section>

    {{-- 4. Campagne : faits, compte à rebours, jauge, moyens ; graphiques des boutiques et des loyers. --}}
    <section class="nuit-section nuit-section-principal" aria-labelledby="campagne-accueil-titre">
        <div class="conteneur nuit-section-corps">
            @if ($campagneAffichee && $infoCampagne)
                @php
                    [$campagneLeger, $campagneFort] = TitreNuit::decouper($campagneAffichee->nom, 2);
                    $surtitre = ['ouverte' => $c['en_cours'], 'bientot' => $c['prochaine'], 'terminee' => $c['derniere']][$infoCampagne['etat']];
                    $moyens = $campagneAffichee->moyensCaution();
                @endphp
                <div class="nuit-section-entete est-centre">
                    <p class="nuit-surtitre">{{ $surtitre }}</p>
                    <h2 id="campagne-accueil-titre" class="nuit-titre">{{ $campagneLeger }}{{ $campagneLeger !== '' ? ' ' : '' }}<strong>{{ $campagneFort }}</strong></h2>
                </div>
                <div class="nuit-campagne nuit-campagne-accueil" data-etat-campagne="{{ $infoCampagne['etat'] }}">
                    <div class="nuit-campagne-cartes">
                        <div class="nuit-carte nuit-info">
                            <p class="nuit-info-titre">{{ $c['libres_titre'] }}</p>
                            <p><span class="nuit-vif">{{ format_nombre($infoCampagne['libres']) }}</span> {{ trim(str_replace(':libres', '', __('public.nuit.campagne.libres', ['total' => format_nombre($infoCampagne['total'])]))) }}</p>
                            <p class="nuit-secondaire">{{ trans_choice('public.nuit.campagne.libres_detail', $infoCampagne['sites'], ['niveaux' => $infoCampagne['niveaux'], 'sites' => $infoCampagne['sites']]) }}</p>
                        </div>
                        @if ($caution)
                            <div class="nuit-carte nuit-info">
                                <p class="nuit-info-titre">{{ $c['caution_titre'] }}</p>
                                <p>{!! str_replace(e($caution), '<span class="nuit-vif">'.e($caution).'</span>', e(__('public.nuit.campagne.caution', ['mois' => $caution]))) !!}</p>
                                <p class="nuit-secondaire">{!! str_replace(e($infoCampagne['delai_caution']), '<span class="nuit-jaune">'.e($infoCampagne['delai_caution']).'</span>', e(__('public.nuit.campagne.a_payer_sous', ['delai' => $infoCampagne['delai_caution']]))) !!}</p>
                            </div>
                        @endif
                        @if ($avance)
                            <div class="nuit-carte nuit-info">
                                <p class="nuit-info-titre">{{ $c['avance_titre'] }}</p>
                                <p>{!! str_replace(e($avance), '<span class="nuit-vif">'.e($avance).'</span>', e(__('public.nuit.campagne.avance', ['mois' => $avance]))) !!}</p>
                                <p class="nuit-secondaire">{!! str_replace(e($infoCampagne['delai_avance']), '<span class="nuit-jaune">'.e($infoCampagne['delai_avance']).'</span>', e(__('public.nuit.campagne.a_payer_sous', ['delai' => $infoCampagne['delai_avance']]))) !!}</p>
                            </div>
                        @endif
                        <div class="nuit-carte nuit-info">
                            <p class="nuit-info-titre">{{ $c['limite_titre'] }}</p>
                            @if ($infoCampagne['limite_uniforme'] !== null)
                                <p><span class="nuit-vif">{{ $infoCampagne['limite_uniforme'] }}</span> {{ trim(str_replace(':n', '', __('public.nuit.campagne.limite_uniforme'))) }}</p>
                            @else
                                <p>{{ __('public.boutiques.limites.par_personne', ['liste' => $campagneAffichee->typesConcernes()->map(fn ($t) => ($t->libelle ?: $t->code).' ('.$campagneAffichee->limitePour($t->id).')')->implode(', ')]) }}</p>
                            @endif
                            <p class="nuit-secondaire">{{ $c['limite_controle'] }}</p>
                        </div>
                    </div>
                    <div class="nuit-carte nuit-campagne-rebours">
                        @if ($infoCampagne['cible'])
                            <x-public.compte-a-rebours :cible="$infoCampagne['cible']" :titre="$infoCampagne['etat'] === 'ouverte' ? $c['cloture_dans'] : $c['ouverture_dans']" />
                        @else
                            <p class="nuit-rebours-titre">{{ __('public.boutiques.etats.'.$infoCampagne['etat'].'.badge') }}</p>
                        @endif
                        <x-public.jauge class="nuit-campagne-jauge" :valeur="$infoCampagne['reservees']" :max="max(1, $infoCampagne['total'])"
                                        :libelle="__('public.nuit.campagne.reservees', ['n' => format_nombre($infoCampagne['reservees']), 'total' => format_nombre($infoCampagne['total'])])" />
                        @if ($moyens->isNotEmpty())
                            <ul class="nuit-moyens">
                                @foreach ($moyens as $moyen)<li>{{ $moyen->libelle }}</li>@endforeach
                            </ul>
                        @endif
                        <x-public.pilule :href="route('boutiques')" class="nuit-campagne-bouton">{{ $a['candidater'] }}</x-public.pilule>
                    </div>
                </div>
            @else
                <div class="nuit-section-entete est-centre">
                    <p class="nuit-surtitre">{{ $c['prochaine'] }}</p>
                    <h2 id="campagne-accueil-titre" class="nuit-titre">{{ $a['campagne_bientot'] }}</h2>
                </div>
            @endif

            @if ($niveaux->isNotEmpty() || $tarifs)
                <div class="nuit-graphiques">
                    @if ($niveaux->isNotEmpty())
                        <x-public.graphique-anneau :titre="$n['anneau_titre']"
                                                   :segments="$niveaux->map(fn ($nv) => ['libelle' => $nv['libelle'], 'valeur' => $nv['total'], 'couleur' => $nv['couleur']])->all()" />
                    @endif
                    @if ($tarifs)
                        <x-public.graphique-barres :titre="__('public.nuit.accueil.barres_titre', ['surface' => $surface])"
                                                   :barres="array_map(fn ($l) => ['libelle' => $court($l), 'valeur' => $l['loyer'], 'etiquette' => format_nombre($l['loyer']), 'couleur' => $l['couleur']], $tarifs['lignes'])" />
                    @endif
                </div>
            @endif
        </div>
    </section>

    {{-- 5. Calendrier : les étapes du projet. --}}
    <x-public.section fond="alterne" :surtitre="$n['calendrier_surtitre']" :titre="$n['calendrier_titre']" :fort="$n['calendrier_fort']" :centre="true">
        <x-public.frise variante="dates" :etapes="$frise" />
    </x-public.section>

    {{-- 6. Tarifs par niveau (grille confirmée). --}}
    <section class="nuit-section nuit-section-principal" aria-labelledby="tarifs-titre">
        <div class="conteneur nuit-section-corps">
            <div class="nuit-tarifs-entete">
                <h2 id="tarifs-titre" class="nuit-titre">{{ $n['tarifs_titre'] }} <strong>{{ $n['tarifs_fort'] }}</strong></h2>
                @if ($tarifs)<span class="nuit-etiquette">{{ $n['grille_confirmee'] }}</span>@endif
            </div>
            @if ($tarifs)
                @php
                    $colonnes = $n['tarifs_colonnes'];
                @endphp
                <x-public.tableau-sombre :legende="__('public.nuit.accueil.tarifs_legende', ['type' => $tarifs['type'], 'surface' => $surface])"
                                         :entetes="[$colonnes['numero'], $colonnes['niveau'], $colonnes['prix'], __('public.nuit.accueil.tarifs_colonnes.loyer', ['surface' => $surface]), $colonnes['caution'], $colonnes['avance'], $colonnes['libres']]">
                    <tbody>
                        @foreach ($tarifs['lignes'] as $i => $ligne)
                            <tr>
                                <td>{{ $i + 1 }}</td>
                                <td><span class="nuit-niveau"><x-public.pastille-icone :couleur="$ligne['couleur']" :texte="$ligne['code']" taille="petite" />{{ $ligne['libelle'] }}</span></td>
                                <td>{{ $ligne['prix'] !== null ? format_fcfa($ligne['prix']) : '' }}</td>
                                <td>{{ format_fcfa($ligne['loyer']) }}</td>
                                <td>{{ trans_choice('public.accueil_raaga.mois', $ligne['mois_caution'], ['n' => $ligne['mois_caution']]) }}</td>
                                <td class="nuit-jaune">{{ trans_choice('public.accueil_raaga.mois', $ligne['mois_avance'], ['n' => $ligne['mois_avance']]) }}</td>
                                <td class="nuit-vif">{{ format_nombre($ligne['libres']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-public.tableau-sombre>
            @else
                <p class="nuit-carte" role="status">{{ $a['durees_vides'] }}</p>
            @endif
        </div>
    </section>

    {{-- 7. Questions fréquentes, sur deux colonnes. --}}
    <x-public.section fond="alterne" :surtitre="$a['faq_titre']" :titre="$n['faq_titre']" :fort="$n['faq_fort']" :centre="true">
        <div class="nuit-faq">
            @foreach ($questions as $question)
                <details class="nuit-carte nuit-question">
                    <summary>{{ $question['q'] }}</summary>
                    <p class="nuit-secondaire">{{ $question['r'] }}</p>
                </details>
            @endforeach
        </div>
        <p class="nuit-liens nuit-liens-centre"><a href="{{ route('faq') }}">{{ $a['faq_toutes'] }}</a></p>
    </x-public.section>

    {{-- 8. Carte « Investir » : même logique que le thème clair (présentation des actions ou annonce). --}}
    <section class="nuit-section nuit-section-principal" aria-labelledby="investir-titre">
        <div class="conteneur nuit-section-corps nuit-investir">
            <h2 id="investir-titre" class="sr-only">{{ $d['titre'] }}</h2>
            @include('public.partials.carte-investir')
        </div>
    </section>
</x-public.layout>
