{{-- Dossier du titulaire au guichet : identité vérifiée, cartes, boutiques attribuées. Aucun montant. --}}
@php
    $g = __('admin.guichet');
    $t = $g['dossier_titulaire'];
    $nom = $titulaire->identite_validee['nom'] ?? $titulaire->name;
@endphp

<x-admin.layout :titre="$t['titre']" :fil="[['libelle' => $g['titre'], 'url' => route('admin.guichet')], ['libelle' => $t['titre']]]">
    <div class="grid gap-[18px] xl:grid-cols-2">
        <section class="bo-carte !mt-0" aria-labelledby="identite-titre">
            <h2 id="identite-titre" class="!text-[1.15rem]">{{ $nom }}</h2>
            <dl class="bo-definitions mt-3">
                @if ($titulaire->telephone)
                    <dt>{{ $t['telephone'] }}</dt><dd>{{ \App\Support\Telephone::formater($titulaire->telephone) }}</dd>
                @endif
                @if ($titulaire->email)
                    <dt>{{ $t['email'] }}</dt><dd>{{ $titulaire->email }}</dd>
                @endif
                @unless ($titulaire->estBackoffice())
                    <dt>{{ $t['kyc'] }}</dt><dd>{{ $titulaire->statut_kyc?->label() }}</dd>
                @endunless
                @if ($titulaire->piece_expire_le)
                    <dt>{{ $t['piece'] }}</dt><dd>{{ $titulaire->piece_expire_le->format('d/m/Y') }}</dd>
                @endif
                <dt>{{ $t['compte'] }}</dt><dd>{{ $titulaire->actif ? $t['actif'] : $t['desactive'] }}</dd>
            </dl>
        </section>

        <section class="bo-carte !mt-0" aria-labelledby="cartes-titre">
            <h2 id="cartes-titre" class="!text-[1.15rem]">{{ $t['cartes'] }}</h2>
            <ul class="mt-3 space-y-2" data-cartes>
                @foreach ($cartes as $carte)
                    <li><strong>{{ $carte->numero }}</strong> · {{ $carte->type->label() }} · {{ $carte->statut->label() }}
                        @if ($carte->valide_jusqu_au) · {{ $g['valide_jusqu_au'] }} {{ $carte->valide_jusqu_au->format('d/m/Y') }} @endif</li>
                @endforeach
            </ul>
        </section>
    </div>

    @can('cartes.gerer')
        {{-- ADMIN : révocation (confirmée par le VALIDATEUR), réémission après une perte, à la demande du titulaire. --}}
        @php($k = __('admin.cartes'))
        <section class="bo-carte" aria-labelledby="gestion-titre" data-gestion-cartes>
            <h2 id="gestion-titre" class="!text-[1.15rem]">{{ $k['gestion'] }}</h2>
            @foreach ($cartes->groupBy(fn ($c) => $c->type->value) as $parType)
                @php($derniere = $parType->first())
                <div class="mt-4 border-t border-ligne pt-4">
                    <p class="font-semibold">{{ $derniere->type->label() }} · {{ $derniere->numero }} · {{ $derniere->statut->label() }}</p>
                    @if ($derniere->courante)
                        @if ($derniere->revocation_demandee_le)
                            <p class="texte-carte mt-1" data-revocation-en-attente>{{ __('admin.cartes.revocation_en_attente', ['date' => $derniere->revocation_demandee_le->format('d/m/Y H:i'), 'motif' => $derniere->revocation_motif]) }}</p>
                        @endif
                        <div class="mt-3 flex flex-wrap gap-2">
                            @unless ($derniere->revocation_demandee_le)
                                <x-admin.confirmation id="revoquer-{{ $derniere->id }}" :titre="$k['revocation']" :message="$k['revocation_aide']"
                                                      :action="route('admin.cartes.revocation', $derniere)" :bouton="$k['revocation']" motif danger taille="petit">
                                    <x-slot:declencheur>{{ $k['revocation'] }}</x-slot:declencheur>
                                </x-admin.confirmation>
                            @endunless
                            <x-admin.confirmation id="perte-{{ $derniere->id }}" :titre="$k['perte']" :message="$k['perte_aide']"
                                                  :action="route('admin.cartes.reemettre', [$titulaire, strtolower($derniere->type->value)])" :bouton="$k['perte']" taille="petit">
                                <x-slot:declencheur>{{ $k['perte'] }}</x-slot:declencheur>
                                <p class="mt-2 font-semibold">{{ __('admin.cartes.perte_confirmation', ['numero' => $derniere->numero]) }}</p>
                            </x-admin.confirmation>
                        </div>
                    @elseif ($derniere->motif === \App\Services\Cartes\Cartes::MOTIF_MANUEL)
                        <p class="texte-carte mt-1">{{ $k['reemettre_aide'] }}</p>
                        <form method="POST" action="{{ route('admin.cartes.reemettre', [$titulaire, strtolower($derniere->type->value)]) }}" class="mt-3">
                            @csrf
                            <x-admin.bouton type="submit" taille="petit" variante="secondaire">{{ $k['reemettre'] }}</x-admin.bouton>
                        </form>
                    @endif
                </div>
            @endforeach
        </section>
    @endcan

    <section class="bo-carte" aria-labelledby="emplacements-titre">
        <h2 id="emplacements-titre" class="!text-[1.15rem]">{{ $t['emplacements'] }}</h2>
        @if ($emplacements->isEmpty())
            <p class="texte-carte mt-2">{{ $t['aucun_emplacement'] }}</p>
        @else
            <ul class="mt-3 space-y-2" data-emplacements>
                @foreach ($emplacements as $r)
                    <li><strong>{{ $r->boutique?->code }}</strong> · {{ $r->boutique?->site?->nom }}</li>
                @endforeach
            </ul>
        @endif
    </section>
</x-admin.layout>
