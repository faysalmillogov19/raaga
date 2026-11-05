{{--
    Dossier d'identité d'un citoyen : identité déclarée, pièces (consultation journalisée), dossiers en cours (références
    et statuts, sans aucun montant), historique. L'ADMIN marque le dossier conforme, ou douteux avec un motif.
--}}
@php
    use App\Enums\Droit;
    use App\Enums\StatutKyc;

    $k = __('admin.kyc');
    $d = $k['dossier'];
    $user = auth()->user();
    $traiter = $user->can(Droit::KycTraiter->value) && $proposition === null;
    $titre = __('admin.kyc.dossier.titre', ['nom' => $citoyen->name]);
    $verif = app(\App\Services\Kyc\VerificationIdentite::class);
    // Conforme seulement si toutes les pièces attendues sont présentes, avec leurs numéros.
    $complet = $verif->complet($citoyen);
    $manquants = $verif->manquants($citoyen);
    $morale = $citoyen->estPersonneMorale();
    $recto = $pieces->get('recto');
@endphp

<x-admin.layout :titre="$titre" :fil="[['libelle' => $k['titre'], 'url' => route('admin.kyc')], ['libelle' => $citoyen->name]]">
    <section class="bo-carte !mt-0" aria-labelledby="identite-titre">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 id="identite-titre" class="!text-[1.15rem]">{{ $d['identite'] }}</h2>
            <div class="flex flex-wrap gap-3">
                @if ($traiter && $citoyen->statut_kyc === StatutKyc::EnAttente && $complet)
                    <x-admin.fenetre id="conforme" :titre="$k['actions']['conforme_titre']" :action="route('admin.kyc.conforme', $citoyen)" :bouton="$k['actions']['confirmer_conforme']">
                        <x-slot:declencheur>{{ $k['actions']['conforme'] }}</x-slot:declencheur>
                        <p class="texte-carte mb-4">{{ $k['aides']['conforme'] }}</p>
                        {{-- Date d'expiration lue sur la pièce : saisie par le citoyen, vérifiée ici (la carte Raaga ne la dépasse jamais). --}}
                        <x-admin.champ nom="piece_expire_le" :libelle="ucfirst($k['champs']['piece_expire_le'])" type="date" :valeur="$citoyen->piece_expire_le?->toDateString()"
                                       :aide="$k['aides']['piece_expire_le']" obligatoire />
                        <x-admin.champ nom="motif" :libelle="ucfirst($k['champs']['motif'])" type="zone" maxlength="1000" />
                    </x-admin.fenetre>
                @endif
                @if ($traiter && in_array($citoyen->statut_kyc, [StatutKyc::EnAttente, StatutKyc::Valide], true))
                    <x-admin.fenetre id="douteux" :titre="$k['actions']['douteux_titre']" :action="route('admin.kyc.douteux', $citoyen)" :bouton="$k['actions']['confirmer_douteux']"
                                     declencheur-variante="secondaire" variante-bouton="danger">
                        <x-slot:declencheur>{{ $k['actions']['douteux'] }}</x-slot:declencheur>
                        <p class="texte-carte mb-4">{{ $k['aides']['douteux'] }}</p>
                        <x-admin.champ nom="motif" :libelle="ucfirst($k['champs']['motif'])" type="zone" maxlength="1000" obligatoire />
                    </x-admin.fenetre>
                @endif
            </div>
        </div>
        @if ($citoyen->statut_kyc === StatutKyc::AMettreAJour)
            <x-admin.message type="avertissement" class="mt-4 !mb-0" data-a-mettre-a-jour>{{ __('admin.kyc.a_mettre_a_jour', ['date' => $citoyen->kyc_maj_demandee_le?->format('d/m/Y') ?? '']) }}</x-admin.message>
        @elseif ($citoyen->statut_kyc === StatutKyc::EnAttente && ! $complet)
            <x-admin.message type="avertissement" class="mt-4 !mb-0" data-dossier-incomplet>{{ __('admin.kyc.manquantes', ['pieces' => implode(', ', array_map(fn ($c) => $k['emplacements'][$c], $manquants ?: ['numeros']))]) }}</x-admin.message>
        @endif
        @if ($proposition)
            <x-admin.message type="info" class="mt-4 !mb-0">{{ $k['proposition_en_attente'] }} : {{ $proposition->motif }}</x-admin.message>
        @endif
        {{-- Investisseur : délai de correction en cours, ou actions gelées (attestation et carte bloquées, dividendes versés). --}}
        @if ($citoyen->actionsGelees())
            <x-admin.message type="avertissement" class="mt-4 !mb-0" data-actions-gelees>{{ __('admin.kyc.actions_gelees', ['date' => $citoyen->actions_gelees_le->format('d/m/Y H:i')]) }}</x-admin.message>
        @elseif ($citoyen->kyc_actions_a_corriger_avant)
            <x-admin.message type="info" class="mt-4 !mb-0" data-delai-investisseur>{{ __('admin.kyc.delai_investisseur', ['date' => $citoyen->kyc_actions_a_corriger_avant->format('d/m/Y H:i')]) }}</x-admin.message>
        @endif
        <dl class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3" data-identite>
            <div><dt class="texte-carte">{{ $d['champs']['nom'] }}</dt><dd class="font-bold">{{ $citoyen->name }}</dd></div>
            <div><dt class="texte-carte">{{ $d['champs']['telephone'] }}</dt><dd>{{ $citoyen->telephone }}</dd></div>
            <div><dt class="texte-carte">{{ $d['champs']['email'] }}</dt><dd>{{ $citoyen->email }}</dd></div>
            <div><dt class="texte-carte">{{ $d['champs']['naissance'] }}</dt><dd>{{ $citoyen->date_naissance?->format('d/m/Y') }}</dd></div>
            <div><dt class="texte-carte">{{ $k['lieu_naissance'] }}</dt><dd data-lieu-naissance>{{ $citoyen->lieu_naissance }}</dd></div>
            <div><dt class="texte-carte">{{ $k['sexe'] }}</dt><dd data-sexe>{{ $citoyen->sexe ? __('public.verification.sexes.'.$citoyen->sexe) : '' }}</dd></div>
            <div><dt class="texte-carte">{{ $d['champs']['pays'] }}</dt><dd>{{ $citoyen->pays_residence }}</dd></div>
            <div><dt class="texte-carte">{{ $k['titulaire'] }}</dt><dd>{{ __('public.titulaires.types.'.($citoyen->type_titulaire?->value ?? 'PHYSIQUE')) }}</dd></div>
            @if ($morale)
                <div><dt class="texte-carte">{{ $k['raison_sociale'] }}</dt><dd class="font-bold" data-raison-sociale>{{ $citoyen->raison_sociale }}</dd></div>
                <div><dt class="texte-carte">{{ $k['forme'] }}</dt><dd>{{ $citoyen->forme_morale ? __('public.titulaires.formes.'.$citoyen->forme_morale) : '' }}</dd></div>
                {{-- Gérant (05/11/2026) : le titulaire du compte ; sa fonction. --}}
                <div><dt class="texte-carte">{{ $k['gerant_fonction'] }}</dt><dd data-gerant-fonction>{{ $citoyen->gerant_fonction }}</dd></div>
            @endif
            @if ($citoyen->nom_commercial)
                <div><dt class="texte-carte">{{ $k['nom_commercial'] }}</dt><dd class="font-bold" data-nom-commercial>{{ $citoyen->nom_commercial }}</dd></div>
            @endif
            {{-- RCCM (05/11/2026) : personne morale, ou personne physique qui l'a déclaré ; jamais sur la carte. --}}
            @if ($morale || $citoyen->rccm)
                <div><dt class="texte-carte">{{ $k['rccm'] }}</dt><dd class="font-mono" data-rccm>{{ $citoyen->rccm }}</dd></div>
                <div><dt class="texte-carte">{{ $k['rccm_date'] }}</dt><dd>{{ $citoyen->rccm_immatricule_le?->format('d/m/Y') }}</dd></div>
                <div><dt class="texte-carte">{{ $k['rccm_greffe'] }}</dt><dd data-greffe>{{ \App\Models\Greffe::libellePour($citoyen) }}@if ($citoyen->rccm_greffe_autre) <span class="texte-carte">({{ $k['greffe_autre'] }})</span>@endif</dd></div>
            @endif
            <div><dt class="texte-carte">{{ $k['numero_ifu'] }}</dt><dd class="font-mono" data-ifu>{{ $citoyen->ifu }}</dd></div>
            <div><dt class="texte-carte">{{ $k['numero_piece'] }}</dt><dd class="font-mono" data-numero-piece>{{ $recto?->numero ?? $citoyen->numero_piece }}</dd></div>
            <div><dt class="texte-carte">{{ $k['expire_le'] }}</dt><dd>{{ ($recto?->expire_le ?? $citoyen->piece_expire_le)?->format('d/m/Y') }}</dd></div>
            <div><dt class="texte-carte">{{ $d['champs']['statut'] }}</dt><dd class="font-bold">{{ $k['statuts'][$citoyen->statut_kyc?->value ?? 'NON_SOUMIS'] }}</dd></div>
            {{-- Formalisation différée (05/11/2026) : inscrit sans IFU, IFU pas encore validé. --}}
            @if (\App\Services\Boutiques\Formalisation::enCours($citoyen))
                <div><dt class="texte-carte">{{ $k['formalisation'] }}</dt><dd class="font-bold" data-formalisation>{{ __(\App\Services\Boutiques\Formalisation::echue($citoyen) ? 'admin.kyc.formalisation_echue' : 'admin.kyc.formalisation_en_cours', ['date' => $citoyen->formalisation_echeance_le->format('d/m/Y')]) }}</dd></div>
            @endif
        </dl>
    </section>

    <section class="bo-carte" aria-labelledby="pieces-titre">
        <h2 id="pieces-titre" class="!text-[1.15rem]">{{ $d['pieces'] }}</h2>
        @if ($pieces->isEmpty())
            <p class="texte-carte mt-2">{{ $d['aucune_piece'] }}</p>
        @else
            <ul class="kyc-pieces mt-3" data-pieces>
                @foreach ($pieces as $piece)
                    <li class="kyc-piece">
                        <p class="font-semibold">{{ $k['pieces'][strtolower($piece->type->value)] ?? $piece->type->value }}@if ($piece->face) · {{ $piece->face->value }}@endif</p>
                        @if ($piece->numero)<p class="font-mono">{{ $piece->numero }}</p>@endif
                        @can(Droit::KycVoirPieces->value)
                            <a href="{{ route('admin.kyc.piece', $piece) }}" class="lien" target="_blank" rel="noopener">{{ $d['voir'] }}</a>
                        @endcan
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- Groupe sanguin (05/11/2026) : imprimé sur la carte de commerçant seulement avec l'accord et un justificatif validé ici. --}}
    @php($g = __('admin.kyc.groupe_sanguin'))
    @if ($citoyen->groupe_sanguin || $justificatifGroupe)
        <section class="bo-carte" aria-labelledby="groupe-sanguin-titre" data-groupe-sanguin>
            <h2 id="groupe-sanguin-titre" class="!text-[1.15rem]">{{ $g['titre'] }}</h2>
            <p class="texte-carte mt-1">{{ $g['intro'] }}</p>
            <dl class="mt-3 grid gap-3 sm:grid-cols-2">
                <div><dt class="texte-carte">{{ $g['declare'] }}</dt><dd class="font-bold">{{ $citoyen->groupe_sanguin_consenti_le ? $citoyen->groupe_sanguin : $g['sans_accord'] }}</dd></div>
                <div>
                    <dt class="texte-carte">{{ $k['pieces']['groupe_sanguin'] }}</dt>
                    <dd data-statut-justificatif>
                        @if ($justificatifGroupe)
                            {{ $g['statuts'][$justificatifGroupe->statut->value] }}
                            @can(Droit::KycVoirPieces->value) · <a href="{{ route('admin.kyc.piece', $justificatifGroupe) }}" class="lien" target="_blank" rel="noopener">{{ $d['voir'] }}</a>@endcan
                        @else
                            {{ $g['aucun'] }}
                        @endif
                    </dd>
                </div>
            </dl>
            @if ($traiter && $justificatifGroupe?->statut === \App\Enums\StatutDocumentKyc::EnAttente)
                <div class="mt-4 flex flex-wrap gap-3">
                    <x-admin.confirmation id="groupe-valider" :titre="$g['valider']" :message="$g['confirmer_valider']" :action="route('admin.kyc.groupe-sanguin.valider', $justificatifGroupe)" :bouton="$g['valider']">
                        <x-slot:declencheur>{{ $g['valider'] }}</x-slot:declencheur>
                    </x-admin.confirmation>
                    <x-admin.confirmation id="groupe-refuser" :titre="$g['refuser']" :message="$g['confirmer_refuser']" :action="route('admin.kyc.groupe-sanguin.refuser', $justificatifGroupe)" :bouton="$g['refuser']"
                                          motif="obligatoire" danger>
                        <x-slot:declencheur>{{ $g['refuser'] }}</x-slot:declencheur>
                    </x-admin.confirmation>
                </div>
            @endif
        </section>
    @endif

    <section class="bo-carte" aria-labelledby="dossiers-titre">
        <h2 id="dossiers-titre" class="!text-[1.15rem]">{{ $d['dossiers'] }}</h2>
        <div class="mt-3 grid gap-4 sm:grid-cols-2" data-dossiers-citoyen>
            @foreach (['souscriptions' => $souscriptions, 'candidatures' => $candidatures] as $cle => $liste)
                <div>
                    <h3 class="font-semibold">{{ $d[$cle] }}</h3>
                    @forelse ($liste as $o)
                        <p><span class="font-mono">{{ $o->reference }}</span> · {{ $o->statut->label() }}</p>
                    @empty
                        <p class="texte-carte">{{ $d['aucun'] }}</p>
                    @endforelse
                </div>
            @endforeach
        </div>
    </section>

    <section class="bo-carte" aria-labelledby="historique-titre">
        <h2 id="historique-titre" class="!text-[1.15rem]">{{ $d['historique'] }}</h2>
        @forelse ($historique as $v)
            <p class="mt-2"><span class="font-semibold">{{ $k['decisions'][$v->statut] }}</span> · {{ $v->auteur?->name }} · {{ $v->created_at->format('d/m/Y H:i') }}
                @if ($v->motif)<span class="texte-carte block">{{ $v->motif }}</span>@endif
                @if ($v->motif_validateur)<span class="texte-carte block">{{ $v->motif_validateur }}</span>@endif</p>
        @empty
            <p class="texte-carte mt-2">{{ $d['aucun_historique'] }}</p>
        @endforelse
    </section>
</x-admin.layout>
