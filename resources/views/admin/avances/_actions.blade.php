{{-- Dernière décision du dossier, puis proposition du RÉGULATEUR : prolonger, annuler (retenue de 0 à 100 %) ou attendre ; motif obligatoire. --}}
@php
    $a = __('admin.avances');
    $enCours = $reservation->decisionsAvance->firstWhere('statut', \App\Models\DecisionAvance::PROPOSEE);
    $derniere = $reservation->decisionsAvance->first();
@endphp
<p data-derniere-decision>{{ $derniere ? $derniere->resume() : $a['aucune_decision'] }}
    @if ($derniere)<span class="texte-carte block">{{ $a['statuts'][$derniere->statut] }}</span>@endif</p>
<div class="mt-2">
    @can(\App\Enums\Droit::AvancesProposer->value)
        @if ($enCours)
            <span class="sr-only" data-proposition-en-cours>{{ $a['en_attente_validateur'] }}</span>
        @else
            <x-admin.fenetre :id="'decision-'.$reservation->id" :titre="__('admin.avances.proposer_titre', ['reference' => $reservation->reference])"
                             :action="route('admin.avances.proposer', $reservation)" :bouton="$a['proposer']" declencheur-taille="petit">
                <x-slot:declencheur>{{ $a['proposer'] }}</x-slot:declencheur>
                <div x-data="{ d: '{{ old('decision', 'PROLONGER') }}' }" @change="if ($event.target.name === 'decision') d = $event.target.value">
                    <p class="texte-carte mb-4">{{ __('admin.avances.proposer_aide', ['date' => $reservation->avance_due_le?->format('d/m/Y H:i')]) }}</p>
                    <x-admin.champ nom="decision" :id="'decision-'.$reservation->id.'-choix'" :libelle="$a['champs']['decision']" type="radio" obligatoire
                                   :options="$a['decisions']" valeur="PROLONGER" />
                    <div x-show="d === 'PROLONGER'" class="mt-4">
                        <x-admin.champ nom="nouvelle_date" :id="'decision-'.$reservation->id.'-date'" :libelle="ucfirst($a['champs']['nouvelle_date'])" type="date"
                                       :min="now()->addDay()->toDateString()" :aide="$a['aides']['nouvelle_date']" />
                    </div>
                    <div x-show="d === 'ANNULER'" x-cloak class="mt-4">
                        <x-admin.champ nom="retenue_pct" :id="'decision-'.$reservation->id.'-retenue'" :libelle="ucfirst($a['champs']['retenue_pct'])" type="number" min="0" max="100"
                                       valeur="0" :aide="$a['aides']['retenue_pct']" />
                    </div>
                    <x-admin.champ nom="motif" :id="'decision-'.$reservation->id.'-motif'" :libelle="ucfirst($a['champs']['motif'])" type="zone" obligatoire minlength="5" maxlength="1000" class="mt-4" />
                </div>
            </x-admin.fenetre>
        @endif
    @endcan
</div>
