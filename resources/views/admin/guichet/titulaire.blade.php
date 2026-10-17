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
