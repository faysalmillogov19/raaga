{{-- Dernière décision sur la retenue, puis proposition du RÉGULATEUR (0 à 100 %, motif obligatoire). --}}
@php
    $a = __('admin.avances');
    $derniere = $remboursement->decisions->sortByDesc('id')->first();
    $enCours = $remboursement->decisions->firstWhere('statut', \App\Models\DecisionAvance::PROPOSEE);
@endphp
<p data-derniere-decision>{{ $derniere ? $derniere->resume() : $a['aucune_decision'] }}
    @if ($derniere)<span class="texte-carte block">{{ $a['statuts'][$derniere->statut] }}</span>@endif</p>
@can(\App\Enums\Droit::AvancesProposer->value)
    @unless ($enCours)
        <div class="mt-2">
            <x-admin.fenetre :id="'retenue-'.$remboursement->id" :titre="__('admin.avances.proposer_titre', ['reference' => $remboursement->reservation?->reference])"
                             :action="route('admin.cautions.proposer', $remboursement)" :bouton="$a['proposer']" declencheur-taille="petit">
                <x-slot:declencheur>{{ $a['proposer'] }}</x-slot:declencheur>
                <p class="texte-carte mb-4">{{ __('admin.avances.proposer_aide', ['caution' => format_fcfa((int) $remboursement->reservation?->caution)]) }}</p>
                <x-admin.champ nom="retenue_pct" :id="'retenue-'.$remboursement->id.'-pct'" :libelle="ucfirst($a['champs']['retenue_pct'])" type="number" min="0" max="100"
                               valeur="0" obligatoire :aide="$a['aides']['retenue_pct']" />
                <x-admin.champ nom="motif" :id="'retenue-'.$remboursement->id.'-motif'" :libelle="ucfirst($a['champs']['motif'])" type="zone" obligatoire minlength="5" maxlength="1000" class="mt-4" />
            </x-admin.fenetre>
        </div>
    @endunless
@endcan
