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
            <x-admin.bouton variante="contour" href="{{ route('admin.appels-fonds.avis', $appel) }}"  target="_blank">{{ $a['avis_appel'] }}</x-admin.bouton>
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
                        <div class="mt-3 flex flex-wrap gap-3">
                            <x-admin.fenetre :id="'rapprocher-'.$d->id" :titre="$a['rapprocher']" :action="route('admin.appels-fonds.rapprocher', $d)" :bouton="$a['rapprocher']">
                                <x-slot:declencheur>{{ $a['rapprocher'] }}</x-slot:declencheur>
                                <p class="texte-carte mb-4">{{ $a['rapprocher_intro'] }}</p>
                                <x-admin.champ nom="montant_recu" :id="'montant-'.$d->id" :libelle="$a['montant_recu']" type="number" min="1" :valeur="$d->montant" required />
                                <x-admin.champ nom="recu_le" :id="'recu-'.$d->id" :libelle="$a['recu_le']" type="date" :valeur="$d->date_virement->toDateString()" required class="mt-4" />
                                <x-admin.champ nom="reference_releve" :id="'releve-'.$d->id" :libelle="$a['reference_releve']" required class="mt-4" />
                            </x-admin.fenetre>
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
