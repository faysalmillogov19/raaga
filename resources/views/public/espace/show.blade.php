{{-- Mon espace (citoyen) : indicateurs, onglets souscriptions / documents / dividendes / profil. Statuts toujours écrits. --}}
@php
    use App\Enums\StatutKyc;
    use App\Enums\StatutSouscription;
    use App\Support\Telephone;

    $kyc = $user->statut_kyc ?? StatutKyc::NonSoumis;
    $prenom = \Illuminate\Support\Str::of($user->prenoms ?: $user->name)->before(' ');
    $badge = [
        'RESERVEE' => 'bg-jaune-pale text-encre', 'PAYEE' => 'bg-vert-pale text-vert-fonce', 'ALLOUEE' => 'bg-vert-fonce text-white',
        'EXPIREE' => 'bg-fond text-gris', 'ANNULEE' => 'bg-fond text-gris', 'REMBOURSEE' => 'bg-fond text-encre',
    ];
    $badgeKyc = ['NON_SOUMIS' => 'bg-fond text-encre', 'EN_ATTENTE' => 'bg-jaune-pale text-encre', 'VALIDE' => 'bg-vert-pale text-vert-fonce', 'REJETE' => 'bg-rouge-pale text-rouge'];
@endphp

<x-public.layout :titre="__('public.espace.titre')">
    <div class="border-b border-ligne bg-fond">
        <div class="conteneur py-6 tab:py-9">
            <h1>{{ __('public.espace.bonjour', ['prenom' => $prenom]) }}</h1>
            <p class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-2">
                <span class="texte-carte">{{ __('public.espace.identite') }}</span>
                <span class="rounded-full px-3 py-1 text-sm font-bold {{ $badgeKyc[$kyc->value] }}">{{ __('public.espace.kyc.'.$kyc->value) }}</span>
                @if ($kyc !== StatutKyc::Valide)
                    <a href="{{ route('espace.verification') }}" class="inline-flex min-h-[44px] items-center font-semibold">{{ __('public.espace.verifier') }}</a>
                @endif
            </p>
        </div>
    </div>

    <div class="conteneur py-8">
        {{-- Indicateurs --}}
        <dl class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            @foreach ($indicateurs as $cle => $valeur)
                <div class="carte flex flex-col-reverse justify-end p-4">
                    <dt class="mt-1 text-sm text-gris">{{ __('public.espace.indicateurs.'.$cle) }}</dt>
                    <dd class="text-lg font-extrabold text-encre tab:text-xl">{{ $valeur }}</dd>
                </div>
            @endforeach
        </dl>

        {{-- Onglets : liens (chaque onglet a son adresse, aucun script requis) --}}
        <nav class="mt-8 border-b border-ligne" aria-label="{{ __('public.espace.onglets_libelle') }}">
            <ul class="grid grid-cols-2 gap-x-1 sm:flex">
                @foreach ($onglets as $cle)
                    <li>
                        <a href="{{ route('espace', ['onglet' => $cle]) }}" @if ($onglet === $cle) aria-current="page" @endif
                           @class(['flex min-h-[48px] items-center border-b-[3px] px-3 font-semibold no-underline sm:px-4',
                                   'border-vert-fonce text-vert-fonce' => $onglet === $cle, 'border-transparent text-texte' => $onglet !== $cle])>
                            {{ __('public.espace.onglets.'.$cle) }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </nav>

        <section class="mt-6" aria-labelledby="onglet-titre">
            <h2 id="onglet-titre" class="sr-only">{{ __('public.espace.onglets.'.$onglet) }}</h2>

            @switch($onglet)
                @case('souscriptions')
                    @forelse ($souscriptions as $s)
                        @php($paiementConfirme = $s->paiements->firstWhere('statut', \App\Enums\StatutPaiement::Confirme))
                        @if ($loop->first)<ul class="space-y-3">@endif
                        <li class="carte p-4 tab:p-5">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <p class="whitespace-nowrap font-bold tracking-wide text-encre">{{ $s->reference }}</p>
                                <span class="rounded-full px-3 py-1 text-sm font-bold {{ $badge[$s->statut->value] }}">{{ $s->statut->label() }}</span>
                            </div>
                            <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 text-[0.95rem] tab:grid-cols-4">
                                <div><dt class="text-sm text-gris">{{ __('public.espace.colonnes.date') }}</dt><dd class="font-semibold">{{ $s->created_at->format('d/m/Y') }}</dd></div>
                                <div><dt class="text-sm text-gris">{{ __('public.espace.colonnes.canal') }}</dt>
                                    <dd class="font-semibold">{{ $paiementConfirme ? __('public.paiement.canaux.'.$paiementConfirme->canal->value) : __('public.espace.canal_souscription.'.$s->canal->value) }}</dd></div>
                                <div><dt class="text-sm text-gris">{{ __('public.espace.colonnes.actions') }}</dt>
                                    <dd class="font-semibold">{{ format_nombre($s->statut === StatutSouscription::Allouee ? (int) $s->nb_actions_allouees : $s->nb_actions_demandees) }}</dd></div>
                                <div><dt class="text-sm text-gris">{{ __('public.espace.colonnes.montant') }}</dt><dd class="font-semibold">{{ format_fcfa($s->montant + $s->frais_souscription) }}</dd></div>
                            </dl>
                            @if ($s->statut === StatutSouscription::Reservee && $s->expire_le->isFuture())
                                <div class="mt-3 flex flex-col gap-2 border-t border-ligne pt-3 sm:flex-row sm:items-center sm:justify-between">
                                    <p class="texte-carte">{{ __('public.espace.payer_avant', ['date' => $s->expire_le->format('d/m/Y \à H\hi')]) }}</p>
                                    <a href="{{ route('paiement.show', $s->reference) }}" class="btn-primaire">{{ __('public.espace.payer') }}</a>
                                </div>
                            @endif
                        </li>
                        @if ($loop->last)</ul>@endif
                    @empty
                        <x-etat-vide icone="souscriptions" :titre="__('public.espace.vides.souscriptions_titre')" :lien="route('souscrire')" :libelle-lien="__('public.espace.vides.souscrire')">
                            {{ __('public.espace.vides.souscriptions') }}
                        </x-etat-vide>
                    @endforelse
                    @break

                @case('boutiques')
                    @forelse ($candidatures as $c)
                        @if ($loop->first)<ul class="space-y-3">@endif
                        <li class="carte p-4 tab:p-5">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <p class="whitespace-nowrap font-bold tracking-wide text-encre">{{ $c->reference }}</p>
                                <span class="rounded-full px-3 py-1 text-sm font-bold {{ ['RESERVEE' => 'bg-jaune-pale text-encre', 'PAYEE' => 'bg-vert-pale text-vert-fonce', 'RETENUE' => 'bg-vert-fonce text-white', 'NON_RETENUE' => 'bg-fond text-encre', 'EXPIREE' => 'bg-fond text-gris'][$c->statut->value] }}">{{ $c->statut->label() }}</span>
                            </div>
                            <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 text-[0.95rem] tab:grid-cols-3">
                                <div><dt class="text-sm text-gris">{{ __('public.boutiques.candidature.campagne') }}</dt><dd class="font-semibold">{{ $c->campagne->nom }}</dd></div>
                                <div><dt class="text-sm text-gris">{{ __('public.boutiques.candidature.categorie') }}</dt><dd class="font-semibold">{{ $c->categorie->intitule() }}</dd></div>
                                <div><dt class="text-sm text-gris">{{ __('public.boutiques.candidature.caution') }}</dt><dd class="font-semibold">{{ format_fcfa($c->caution) }}</dd></div>
                                @if ($c->boutique)
                                    <div><dt class="text-sm text-gris">{{ __('public.boutiques.candidature.boutique') }}</dt><dd class="whitespace-nowrap font-extrabold text-vert-fonce">{{ $c->boutique->reference() }}</dd></div>
                                @endif
                            </dl>
                            <a href="{{ route('boutiques.candidature', $c->reference) }}" class="btn-secondaire mt-3">{{ __('public.espace.voir_candidature') }}</a>
                        </li>
                        @if ($loop->last)</ul>@endif
                    @empty
                        <x-etat-vide icone="boutiques" :titre="__('public.espace.vides.boutiques_titre')" :lien="route('boutiques')" :libelle-lien="__('public.boutiques.titre')">
                            {{ __('public.espace.vides.boutiques') }}
                        </x-etat-vide>
                    @endforelse
                    @break

                @case('documents')
                    @if ($recus->isEmpty() && $reglements->isEmpty() && $attestations->isEmpty())
                        <x-etat-vide icone="contenus" :titre="__('public.espace.vides.documents_titre')">
                            {{ __('public.espace.vides.documents') }}
                        </x-etat-vide>
                    @else
                        <ul class="space-y-3">
                            @foreach ($attestations as $ligne)
                                <li class="carte flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
                                    <div><p class="font-semibold text-encre">{{ __('public.espace.documents.attestation', ['n' => format_nombre($ligne->nb_actions)]) }}</p>
                                        <p class="texte-carte">{{ $ligne->tranche->nom }} · {{ __('public.espace.documents.certificat', ['numero' => $ligne->numero_certificat]) }} · {{ $ligne->date_inscription->format('d/m/Y') }}</p></div>
                                    <a href="{{ route('espace.documents.attestation', $ligne->numero_certificat) }}" class="btn-secondaire" download>{{ __('public.espace.documents.telecharger') }}</a>
                                </li>
                            @endforeach
                            @foreach ($recus as $r)
                                <li class="carte flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
                                    <div><p class="font-semibold text-encre">{{ __('public.espace.documents.recu', ['numero' => \App\Support\Recu::numero($r['paiement'])]) }}</p>
                                        <p class="texte-carte">{{ $r['souscription']->reference }} · {{ $r['paiement']->date_confirmation->format('d/m/Y') }}</p></div>
                                    <a href="{{ route('paiement.recu', $r['souscription']->reference) }}" class="btn-secondaire" download>{{ __('public.espace.documents.telecharger') }}</a>
                                </li>
                            @endforeach
                            @foreach ($reglements as $a)
                                <li class="carte flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
                                    <div><p class="font-semibold text-encre">{{ __('public.espace.documents.reglement', ['tranche' => $a->souscription->tranche->nom, 'version' => $a->version_document]) }}</p>
                                        <p class="texte-carte">{{ __('public.espace.documents.accepte_le', ['date' => $a->accepte_le->format('d/m/Y')]) }}</p></div>
                                    <a href="{{ route('espace.documents.reglement', $a->souscription->reference) }}" class="btn-secondaire">{{ __('public.espace.documents.consulter') }}</a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    @break

                @case('dividendes')
                    <x-etat-vide icone="dividendes" :titre="__('public.espace.vides.dividendes_titre')">
                        {{ __('public.espace.vides.dividendes') }}
                    </x-etat-vide>
                    <div class="carte mx-auto mt-4 max-w-xl p-5">
                        <h3 class="!text-base">{{ __('public.espace.compte_versement') }}</h3>
                        <p class="mt-1 texte-carte">{{ __('public.espace.compte_versement_vide') }}</p>
                    </div>
                    @break

                @case('profil')
                    <div class="carte max-w-2xl p-5">
                        <dl class="space-y-3 text-[0.95rem]">
                            @foreach ([
                                'nom' => trim(($user->prenoms ?? '').' '.($user->nom ?? '')) ?: $user->name,
                                'telephone' => $user->telephone ? Telephone::formaterLocal($user->telephone) : null,
                                'naissance' => $user->date_naissance?->format('d/m/Y'),
                                'residence' => config('raaga.inscription.pays.'.($user->pays_residence ?: 'BF')),
                                'region' => $user->region,
                            ] as $cle => $valeur)
                                @if ($valeur)
                                    <div class="flex flex-col gap-0.5 sm:flex-row sm:justify-between sm:gap-4"><dt class="text-gris">{{ __('public.espace.profil.'.$cle) }}</dt><dd class="font-semibold text-encre">{{ $valeur }}</dd></div>
                                @endif
                            @endforeach
                            <div class="flex flex-col gap-0.5 border-t border-ligne pt-3 sm:flex-row sm:justify-between sm:gap-4"><dt class="text-gris">{{ __('public.espace.profil.beneficiaire') }}</dt>
                                <dd class="text-encre">{{ __('public.espace.profil.beneficiaire_vide') }}</dd></div>
                        </dl>
                        <a href="{{ route('espace.profil') }}" class="btn-secondaire mt-5">{{ __('public.espace.profil.modifier') }}</a>
                    </div>
                    @break
            @endswitch
        </section>
    </div>
</x-public.layout>
