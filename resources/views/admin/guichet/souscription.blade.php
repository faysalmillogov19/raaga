{{--
    Souscription d'actions au guichet (AGENT DE GUICHET) pour une personne présente, étape par étape : téléphone et code
    SMS (présence et accord), compte, identité validée sur place, souscription, encaissement, reçu.
--}}
@php
    use App\Services\Guichet\SouscriptionGuichet;
    use App\Services\Kyc\VerificationIdentite;

    $g = __('admin.guichet.souscription');
    $v = __('public.verification');
    $etapes = $g['etapes'];
    $rang = array_search($etape, array_keys($etapes), true) + 1;
@endphp

<x-admin.layout :titre="$g['titre']" :fil="[['libelle' => __('admin.guichet.titre'), 'url' => route('admin.guichet')], ['libelle' => $g['titre']]]">
    <section class="bo-carte !mt-0" aria-labelledby="guichet-souscription-titre" data-etape="{{ $etape }}">
        <h2 id="guichet-souscription-titre" class="!text-[1.15rem]">{{ $g['titre'] }}</h2>
        <p class="texte-carte mt-1 max-w-3xl">{{ $g['intro'] }}</p>
        <ol class="guichet-etapes mt-4" aria-label="{{ __('admin.guichet.souscription.etape_sur', ['n' => $rang, 'total' => count($etapes)]) }}">
            @foreach ($etapes as $cle => $libelle)
                <li @class(['est-faite' => $loop->iteration < $rang, 'est-courante' => $cle === $etape]) @if ($cle === $etape) aria-current="step" @endif>
                    <span class="guichet-etape-numero" aria-hidden="true">{{ $loop->iteration }}</span>{{ $libelle }}
                </li>
            @endforeach
        </ol>
        @if ($citoyen)
            <p class="mt-4" data-personne><strong>{{ $citoyen->name }}</strong> · {{ \App\Support\Telephone::formaterLocal($citoyen->telephone) }}</p>
        @endif
    </section>

    @if (! $tranche && in_array($etape, ['telephone', 'compte', 'identite', 'souscription'], true))
        <x-admin.message type="info" class="mt-4" data-fermee>{{ $g['fermee'] }}</x-admin.message>
    @endif

    <section class="bo-carte" aria-labelledby="etape-titre">
        <h2 id="etape-titre" class="!text-[1.05rem]">{{ $etapes[$etape] }}</h2>

        @switch($etape)
            @case('telephone')
                @if (! $codeEnvoye)
                    <form method="POST" action="{{ route('admin.guichet-souscription.telephone') }}" class="mt-3 grid max-w-xl gap-4" novalidate>
                        @csrf
                        <x-admin.champ nom="telephone" type="tel" :libelle="$g['telephone']" :aide="$g['telephone_aide']" inputmode="tel" autocomplete="off" required />
                        <div><x-admin.bouton type="submit" icone="telephone">{{ $g['envoyer_code'] }}</x-admin.bouton></div>
                    </form>
                @else
                    <p class="mt-2" data-statut-compte>{{ $existant ? __('admin.guichet.souscription.compte_existant', ['nom' => $existant]) : $g['compte_nouveau'] }}</p>
                    <form method="POST" action="{{ route('admin.guichet-souscription.code') }}" class="mt-3 grid max-w-xl gap-4" novalidate>
                        @csrf
                        <x-admin.champ nom="code" :libelle="$g['code']" :aide="__('admin.guichet.souscription.code_aide', ['telephone' => \App\Support\Telephone::formaterLocal($telephone)])"
                                       inputmode="numeric" maxlength="6" autocomplete="one-time-code" required />
                        <div class="flex flex-wrap gap-3">
                            <x-admin.bouton type="submit" icone="check">{{ $g['verifier_code'] }}</x-admin.bouton>
                            <x-admin.bouton type="submit" variante="contour" formaction="{{ route('admin.guichet-souscription.renvoyer') }}">{{ $g['renvoyer'] }}</x-admin.bouton>
                            <x-admin.bouton type="submit" variante="discret" formaction="{{ route('admin.guichet-souscription.terminer') }}">{{ $g['changer_numero'] }}</x-admin.bouton>
                        </div>
                    </form>
                @endif
                @break

            @case('compte')
                <p class="mt-2">{{ $g['compte_nouveau'] }}</p>
                <form method="POST" action="{{ route('admin.guichet-souscription.compte') }}" class="mt-3 grid max-w-3xl gap-4 tab:grid-cols-2" novalidate
                      x-data="{ titulaire: @js(old('type_titulaire', 'PHYSIQUE')), pays: @js(old('pays_residence', 'BF')) }">
                    @csrf
                    <div @change="titulaire = $event.target.value" class="tab:col-span-2"><x-admin.champ nom="type_titulaire" type="radio" :libelle="$g['champs']['type_titulaire']" :options="collect(\App\Enums\TypeTitulaire::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()])->all()"
                                   valeur="PHYSIQUE" required /></div>
                    <div x-show="titulaire === 'MORALE'" x-cloak class="grid gap-4 tab:col-span-2 tab:grid-cols-2">
                        <x-admin.champ nom="raison_sociale" :libelle="__('public.titulaires.raison_sociale')" maxlength="200" />
                        <x-admin.champ nom="forme_morale" type="liste" :libelle="__('public.titulaires.forme')" :options="array_combine(\App\Enums\TypeTitulaire::FORMES, \App\Enums\TypeTitulaire::FORMES)" :vide="$g['champs']['choisir']" />
                    </div>
                    <x-admin.champ nom="nom" :libelle="__('public.compte.inscription.nom')" maxlength="100" autocomplete="off" required />
                    <x-admin.champ nom="prenoms" :libelle="__('public.compte.inscription.prenoms')" maxlength="150" autocomplete="off" required />
                    <x-admin.champ nom="date_naissance" type="date" :libelle="__('public.compte.inscription.date_naissance')" required />
                    <x-admin.champ nom="pays_residence" type="liste" :libelle="$g['champs']['pays']" :options="$pays" valeur="BF" required x-model="pays" />
                    <div x-show="pays === 'BF'">
                        <x-admin.champ nom="region" type="liste" :libelle="__('public.compte.inscription.region')" :options="array_combine($regions, $regions)" :vide="$g['champs']['choisir']" />
                    </div>
                    <x-admin.champ nom="conditions" type="case" :libelle="$g['conditions']" required class="tab:col-span-2" />
                    <div class="tab:col-span-2"><x-admin.bouton type="submit" icone="utilisateur">{{ $g['creer_compte'] }}</x-admin.bouton></div>
                </form>
                @break

            @case('identite')
                @php
                    $emplacements = array_keys(VerificationIdentite::emplacements(VerificationIdentite::titulaire($citoyen), \App\Enums\TypeDocumentKyc::Cnib));
                    $demandes = array_values(array_intersect($emplacements, $aFournir));
                @endphp
                <form method="POST" action="{{ route('admin.guichet-souscription.identite') }}" enctype="multipart/form-data" class="mt-3 grid max-w-3xl gap-4" novalidate
                      x-data="{ piece: @js(old('type_piece', $typePiece?->value ?? 'CNIB')) }">
                    @csrf
                    @if ($demandes === [])
                        <p data-dossier-complet>{{ $g['identite_aucune_piece'] }}</p>
                    @else
                        <p>{{ $g['identite_intro'] }}</p>
                        @if (in_array('recto', $demandes, true))
                            <div @change="piece = $event.target.value"><x-admin.champ nom="type_piece" type="radio" :libelle="$v['type_piece']" :options="$v['types']" :valeur="$typePiece?->value ?? 'CNIB'" required /></div>
                            <div class="grid gap-4 tab:grid-cols-2">
                                <x-admin.champ nom="numero_piece" :libelle="$g['champs']['numero_piece']" :aide="$v['aides']['numero_piece']" maxlength="40" autocomplete="off" required />
                                <x-admin.champ nom="piece_expire_le" type="date" :libelle="$v['expiration']" required />
                            </div>
                        @endif
                        @foreach ($demandes as $cle)
                            <div @if ($cle === 'verso') x-show="piece !== 'PASSEPORT'" @endif>
                                <x-admin.depot-fichier :nom="$cle" :libelle="$g['pieces'][$cle] ?? $v['pieces'][$cle]"
                                                       :formats="in_array($cle, VerificationIdentite::DOCUMENTS, true) ? '.jpg, .png, .pdf' : '.jpg, .png'" :tailleMo="5"
                                                       :accept="in_array($cle, VerificationIdentite::DOCUMENTS, true) ? 'image/jpeg,image/png,application/pdf' : 'image/jpeg,image/png'"
                                                       capture="{{ $cle === 'selfie' ? 'user' : 'environment' }}" />
                            </div>
                            @if ($cle === 'rccm')
                                <x-admin.champ nom="numero_rccm" :libelle="$v['attributs']['numero_rccm']" :aide="$v['aides']['numero_rccm']" maxlength="60" />
                            @elseif ($cle === 'ifu')
                                <x-admin.champ nom="numero_ifu" :libelle="$v['attributs']['numero_ifu']" maxlength="40" />
                            @endif
                        @endforeach
                    @endif
                    <x-admin.champ nom="piece_conforme" type="case" :libelle="$g['piece_conforme']" required />
                    <div><x-admin.bouton type="submit" icone="kyc">{{ $g['valider_identite'] }}</x-admin.bouton></div>
                </form>
                @break

            @case('souscription')
                @if ($tranche)
                    <dl class="bo-definitions mt-3">
                        <dt>{{ $g['champs']['tranche'] }}</dt><dd>{{ $tranche->nom }}</dd>
                        <dt>{{ $g['champs']['prix'] }}</dt><dd>{{ format_fcfa($tranche->valeur_unitaire) }}</dd>
                        <dt>{{ $g['champs']['minimum'] }}</dt><dd>{{ format_nombre(max(1, (int) $tranche->min_actions_par_souscription)) }} {{ $g['champs']['actions'] }}</dd>
                        @if ($deja && $deja['actions'] > 0)
                            <dt>{{ $g['champs']['deja'] }}</dt><dd data-deja>{{ format_nombre($deja['actions']) }}</dd>
                        @endif
                    </dl>
                    <form method="POST" action="{{ route('admin.guichet-souscription.souscrire') }}" enctype="multipart/form-data" class="mt-4 grid max-w-3xl gap-4" novalidate>
                        @csrf
                        <x-admin.champ nom="nb_actions" type="number" :libelle="$g['champs']['nb_actions']" min="1" step="1" required />
                        <p>{{ $g['souscription_intro'] }}</p>
                        <x-admin.cases nom="engagements" :libelle="__('public.souscription.etape_engagements')" obligatoire class="guichet-engagements"
                                       :options="collect($engagements)->mapWithKeys(fn ($d) => [$d->value => __('public.souscription.engagements.'.$d->value, ['version' => $tranche->version])])->all()" />
                        @if ($tranche->seuil_kyc_renforce)
                            <x-admin.depot-fichier nom="justificatif" :libelle="__('public.souscription.justificatif_titre')" formats=".pdf, .jpg, .png" :tailleMo="5"
                                                   :aide="__('public.souscription.justificatif_texte', ['seuil' => format_fcfa($tranche->seuil_kyc_renforce)])" accept="application/pdf,image/jpeg,image/png" />
                        @endif
                        <div><x-admin.bouton type="submit" icone="souscriptions">{{ $g['reserver'] }}</x-admin.bouton></div>
                    </form>
                @endif
                @break

            @case('paiement')
                @php($total = $souscription->montant + $souscription->frais_souscription)
                <p class="mt-2">{{ __('admin.guichet.souscription.resume', ['actions' => format_nombre($souscription->nb_actions_demandees), 'montant' => format_fcfa($total), 'reference' => $souscription->reference]) }}</p>
                <p class="guichet-montant mt-3" data-a-encaisser>{{ $g['a_payer'] }} : <strong>{{ format_fcfa($total) }}</strong></p>
                <form method="POST" action="{{ route('admin.guichet-souscription.encaisser') }}" class="mt-4 grid max-w-xl gap-4" novalidate
                      x-data="{ moyen: @js(old('moyen', $moyens[0]->code ?? '')), especes: @js(collect($moyens)->reject(fn ($m) => SouscriptionGuichet::referenceExigee($m))->pluck('code')->all()) }">
                    @csrf
                    <div @change="moyen = $event.target.value"><x-admin.champ nom="moyen" type="radio" :libelle="$g['moyen']" :options="collect($moyens)->mapWithKeys(fn ($m) => [$m->code => $m->label()])->all()"
                                   :valeur="$moyens[0]->code ?? null" required /></div>
                    <div x-show="! especes.includes(moyen)">
                        <x-admin.champ nom="reference" :libelle="$g['reference']" :aide="$g['reference_aide']" maxlength="80" autocomplete="off" />
                    </div>
                    <x-admin.champ nom="montant_recu" type="case" :libelle="__('admin.guichet.souscription.montant_recu', ['montant' => format_fcfa($total)])" required />
                    <div class="flex flex-wrap gap-3">
                        <x-admin.bouton type="submit" icone="check">{{ $g['encaisser'] }}</x-admin.bouton>
                        <x-admin.bouton type="submit" variante="discret" formaction="{{ route('admin.guichet-souscription.terminer') }}">{{ $g['abandonner'] }}</x-admin.bouton>
                    </div>
                </form>
                @break

            @case('recu')
                <p class="mt-2" data-encaisse>{{ __('admin.guichet.souscription.resume', ['actions' => format_nombre($souscription->nb_actions_demandees), 'montant' => format_fcfa($souscription->montant + $souscription->frais_souscription), 'reference' => $souscription->reference]) }}</p>
                <div class="mt-4 flex flex-wrap gap-3">
                    <x-admin.bouton :href="route('admin.guichet-souscription.recu', $souscription->reference)" icone="lecture" target="_blank" rel="noopener">{{ $g['recu'] }}</x-admin.bouton>
                    <form method="POST" action="{{ route('admin.guichet-souscription.terminer') }}">
                        @csrf
                        <x-admin.bouton type="submit" variante="contour" icone="plus">{{ $g['nouvelle'] }}</x-admin.bouton>
                    </form>
                </div>
                @break
        @endswitch
    </section>

    @if (in_array($etape, ['compte', 'identite', 'souscription'], true))
        <form method="POST" action="{{ route('admin.guichet-souscription.terminer') }}" class="mt-4">
            @csrf
            <x-admin.bouton type="submit" variante="discret">{{ $g['abandonner'] }}</x-admin.bouton>
        </form>
    @endif
</x-admin.layout>
