@php
    use App\Enums\Droit;
    use App\Models\DecisionPhase;

    $p = __('admin.phase');
    $oui = fn (bool $v) => $v ? $p['oui'] : $p['non'];
    $effet = ! $presentation ? 'masquee' : ($souscription && $tranche ? 'ouverte' : 'bientot');
@endphp

<x-admin.layout :titre="$p['titre']">
    <section class="bo-carte !mt-0" aria-labelledby="vigueur-titre">
        <h2 id="vigueur-titre" class="!text-[1.15rem]">{{ $p['en_vigueur'] }}</h2>
        <p class="texte-carte mt-1 max-w-3xl">{{ $p['intro'] }}</p>
        <dl class="mt-4 grid gap-4 sm:grid-cols-2">
            @foreach (['presentation_actions' => $presentation, 'souscription_actions' => $souscription] as $cle => $valeur)
                <div>
                    <dt class="font-bold text-encre">{{ $p['parametres'][$cle] }}</dt>
                    <dd class="mt-1"><span class="bo-etiquette">{{ $oui($valeur) }}</span></dd>
                    <dd class="texte-carte mt-1">{{ $p['aides'][$cle] }}</dd>
                </div>
            @endforeach
        </dl>
        <p class="mt-4">
            {{ $enVigueur ? __('admin.phase.confirmee_par', ['date' => $enVigueur->decide_le->format('d/m/Y à H:i'), 'nom' => $enVigueur->decideur?->name ?? '—']) : $p['initiale'] }}
            {{ $tranche ? __('admin.phase.tranche_ouverte', ['nom' => $tranche->nom]) : $p['aucune_tranche'] }}
        </p>
        <p class="mt-1"><strong>{{ $p['effet'] }} :</strong> {{ $p['effets'][$effet] }}</p>
    </section>

    @if ($enAttente)
        <section class="bo-carte" aria-labelledby="attente-titre">
            <h2 id="attente-titre" class="!text-[1.15rem]">{{ $p['en_attente'] }}</h2>
            <p class="texte-carte mt-1">{{ __('admin.phase.proposee_par', ['date' => $enAttente->created_at->format('d/m/Y à H:i'), 'nom' => $enAttente->auteur?->name ?? '—']) }}</p>
            <dl class="mt-3 grid gap-2 sm:grid-cols-2">
                <div><dt class="texte-carte">{{ $p['parametres']['presentation_actions'] }}</dt><dd class="font-bold">{{ $oui($enAttente->presentation_actions) }}</dd></div>
                <div><dt class="texte-carte">{{ $p['parametres']['souscription_actions'] }}</dt><dd class="font-bold">{{ $oui($enAttente->souscription_actions) }}</dd></div>
                <div class="sm:col-span-2"><dt class="texte-carte">{{ $p['motif'] }}</dt><dd>{{ $enAttente->motif }}</dd></div>
            </dl>
            @can(Droit::PhaseConfirmer->value)
                <div class="mt-4 flex flex-wrap gap-3">
                    <x-admin.confirmation etape="confirmer" id="confirmer-phase" :titre="$p['confirmer']" :message="$p['confirmer_message']"
                                          :action="route('admin.phase.confirmer', $enAttente->id)" :bouton="$p['confirmer']">
                        <x-slot:declencheur>{{ $p['confirmer'] }}</x-slot:declencheur>
                    </x-admin.confirmation>
                    <x-admin.confirmation etape="rejeter" id="rejeter-phase" :titre="$p['rejeter']" :message="$p['rejeter_message']"
                                          :action="route('admin.phase.rejeter', $enAttente->id)" :bouton="$p['rejeter']" motif danger>
                        <x-slot:declencheur>{{ $p['rejeter'] }}</x-slot:declencheur>
                    </x-admin.confirmation>
                </div>
                @error('motif') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
            @endcan
        </section>
    @endif

    @can(Droit::PhaseProposer->value)
        @unless ($enAttente)
            <section class="bo-carte" aria-labelledby="proposer-titre">
                <h2 id="proposer-titre" class="!text-[1.15rem]">{{ $p['proposer'] }}</h2>
                <form method="POST" action="{{ route('admin.phase.proposer') }}" class="mt-3 grid max-w-2xl gap-4" novalidate>
                    @csrf
                    @foreach (['presentation_actions' => $presentation, 'souscription_actions' => $souscription] as $cle => $valeur)
                        <fieldset>
                            <legend class="font-bold text-encre">{{ $p['parametres'][$cle] }}</legend>
                            <div class="mt-1 flex gap-5">
                                @foreach ([1 => $p['oui'], 0 => $p['non']] as $v => $libelle)
                                    <label class="inline-flex items-center gap-2 font-normal">
                                        <input type="radio" name="{{ $cle }}" value="{{ $v }}" @checked((int) old($cle, (int) $valeur) === $v)> {{ $libelle }}
                                    </label>
                                @endforeach
                            </div>
                            <p class="aide-champ">{{ $p['aides'][$cle] }}</p>
                        </fieldset>
                    @endforeach
                    <x-admin.champ nom="motif" :libelle="$p['motif']" type="textarea" :valeur="old('motif')" obligatoire />
                    <div><x-admin.bouton variante="principal" type="submit">{{ $p['envoyer'] }}</x-admin.bouton></div>
                </form>
            </section>
        @endunless
    @endcan

    <section class="bo-carte" aria-labelledby="historique-titre">
        <h2 id="historique-titre" class="!text-[1.15rem]">{{ $p['historique'] }}</h2>
        @if ($decisions->isEmpty())
            <p class="texte-carte mt-2">{{ $p['aucune'] }}</p>
        @else
            <div class="mt-3 overflow-x-auto">
                <table class="bo-tableau">
                    <thead><tr>@foreach ($p['colonnes'] as $libelle)<th scope="col">{{ $libelle }}</th>@endforeach</tr></thead>
                    <tbody>
                        @foreach ($decisions as $d)
                            <tr>
                                <td class="whitespace-nowrap">{{ $d->created_at->format('d/m/Y H:i') }}</td>
                                <td>{{ $d->auteur?->name ?? '—' }}</td>
                                <td>{{ $p['parametres']['presentation_actions'] }} : {{ $oui($d->presentation_actions) }}<br>
                                    {{ $p['parametres']['souscription_actions'] }} : {{ $oui($d->souscription_actions) }}</td>
                                <td>{{ $d->motif }}</td>
                                <td><span class="bo-etiquette">{{ $p['statuts'][$d->statut] }}</span></td>
                                <td>@if ($d->decide_le){{ $d->decideur?->name ?? '—' }}, {{ $d->decide_le->format('d/m/Y H:i') }}@if ($d->motif_rejet)<br>{{ $d->motif_rejet }}@endif @else · @endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</x-admin.layout>
