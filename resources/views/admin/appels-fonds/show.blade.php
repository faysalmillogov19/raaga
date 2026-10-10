@php
    use App\Enums\Droit;
    use App\Enums\StatutDeclarationVirement;

    $a = __('institutionnel.bo.appels');
    $col = $a['colonnes'];
    $titre = $appel->convention->reference.' · n° '.$appel->numero;
@endphp

<x-admin.layout :titre="$titre" :fil="[['libelle' => $a['titre'], 'url' => route('admin.appels-fonds')], ['libelle' => $titre]]">
    <section class="bo-carte !mt-0" aria-labelledby="appel-titre">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <h2 id="appel-titre" class="!text-[1.15rem]">{{ $appel->libelle }}</h2>
            <a href="{{ route('admin.appels-fonds.avis', $appel) }}" class="bo-bouton bo-bouton-secondaire" target="_blank">{{ $a['avis_appel'] }}</a>
        </div>
        <dl class="bo-definitions mt-3">
            <dt>{{ $col['institution'] }}</dt><dd>{{ $appel->institution->denomination }}</dd>
            <dt>{{ $col['statut'] }}</dt><dd><x-institutionnel.statut :statut="$appel->statut" /></dd>
            <dt>{{ $col['echeance'] }}</dt><dd>{{ $appel->echeance->translatedFormat('j F Y') }}</dd>
            <dt>{{ $col['montant'] }}</dt><dd>{{ format_fcfa($appel->montant) }}</dd>
            <dt>{{ $col['recu'] }}</dt><dd>{{ format_fcfa($appel->montant_recu) }}</dd>
            <dt>{{ $col['reference'] }}</dt><dd class="font-mono font-bold">{{ $appel->reference_virement }}</dd>
        </dl>
    </section>

    <section class="bo-carte" aria-labelledby="declarations-titre">
        <h2 id="declarations-titre" class="!text-[1.15rem]">{{ $a['declarations'] }}</h2>
        @forelse ($appel->declarations as $d)
            <article class="mt-4 rounded-charte border border-ligne p-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="font-bold text-encre">{{ format_fcfa($d->montant) }} · {{ $d->date_virement->format('d/m/Y') }} · <span class="font-mono">{{ $d->reference }}</span></p>
                    <x-institutionnel.statut :statut="$d->statut" />
                </div>
                <p class="texte-carte mt-1">{{ $d->declarant?->name }} · {{ $d->created_at->format('d/m/Y H:i') }}
                    @if ($d->avis_chemin) · <a href="{{ route('admin.appels-fonds.avis-virement', $d) }}" target="_blank">{{ $a['avis_virement'] }}</a>@endif
                </p>
                @if ($d->statut === StatutDeclarationVirement::Rapprochee)
                    <p class="mt-1">{{ $a['montant_recu'] }} : {{ format_fcfa($d->montant_recu) }} · {{ $d->recu_le->format('d/m/Y') }} · {{ $d->reference_releve }}</p>
                @elseif ($d->motif_rejet)
                    <p class="mt-1 text-rouge">{{ __('institutionnel.motif_rejet', ['motif' => $d->motif_rejet]) }}</p>
                @endif

                @if ($d->statut === StatutDeclarationVirement::Declaree)
                    @can(Droit::AppelsFondsRapprocher->value)
                        <p class="texte-carte mt-3">{{ $a['rapprocher_intro'] }}</p>
                        <form method="POST" action="{{ route('admin.appels-fonds.rapprocher', $d) }}" class="mt-2 grid gap-4 lg:grid-cols-[1fr_1fr_1fr_auto] lg:items-end">
                            @csrf
                            <x-admin.champ nom="montant_recu" :libelle="$a['montant_recu']" type="number" min="1" :valeur="$d->montant" required />
                            <x-admin.champ nom="recu_le" :libelle="$a['recu_le']" type="date" :valeur="$d->date_virement->toDateString()" required />
                            <x-admin.champ nom="reference_releve" :libelle="$a['reference_releve']" required />
                            <button type="submit" class="bo-bouton bo-bouton-principal">{{ $a['rapprocher'] }}</button>
                        </form>
                        <div class="mt-3">
                            <x-admin.confirmation :id="'rejeter-'.$d->id" :titre="$a['rejeter']" :message="$a['rejeter_message']" :action="route('admin.appels-fonds.rejeter', $d)" :bouton="$a['rejeter']" motif danger>
                                <x-slot:declencheur>{{ $a['rejeter'] }}</x-slot:declencheur>
                            </x-admin.confirmation>
                        </div>
                    @endcan
                @endif
            </article>
        @empty
            <p class="texte-carte mt-2">—</p>
        @endforelse
    </section>
</x-admin.layout>
