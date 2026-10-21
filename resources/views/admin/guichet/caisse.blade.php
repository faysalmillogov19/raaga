{{-- Caisse de l'agent de guichet : encaissements non clôturés par jour, totaux par moyen, clôture d'un jour. --}}
@php
    use App\Services\Guichet\CaisseGuichet;

    $c = __('admin.guichet.caisse');
    $col = $c['colonnes'];
@endphp

<x-admin.layout :titre="$c['titre']" :fil="[['libelle' => __('admin.guichet.titre'), 'url' => route('admin.guichet')], ['libelle' => $c['titre']]]">
    <section class="bo-carte !mt-0" aria-labelledby="caisse-titre">
        <h2 id="caisse-titre" class="!text-[1.15rem]">{{ $c['titre'] }}</h2>
        <p class="texte-carte mt-1 max-w-3xl">{{ $c['intro'] }}</p>
        @foreach ($clotures as $cloture)
            <p class="mt-3" data-cloture>{{ __('admin.guichet.caisse.cloture_ligne', ['heure' => $cloture->cloturee_le->format('H\hi'), 'nombre' => $cloture->nb_encaissements, 'total' => format_fcfa($cloture->total_montant)]) }}</p>
        @endforeach
        @error('jour')<x-admin.message type="erreur" class="mt-3">{{ $message }}</x-admin.message>@enderror
    </section>

    @forelse ($jours as $jour => $paiements)
        @php
            $date = \Carbon\CarbonImmutable::parse($jour)->format('d/m/Y');
            $total = CaisseGuichet::total($paiements);
        @endphp
        <section class="bo-carte" aria-labelledby="jour-{{ $jour }}" data-jour="{{ $jour }}">
            <h2 id="jour-{{ $jour }}" class="!text-[1.05rem]">{{ __('admin.guichet.caisse.jour', ['date' => $date]) }}</h2>
            <div class="mt-3 overflow-x-auto" role="region" aria-labelledby="jour-{{ $jour }}" tabindex="0">
                <table class="bo-tableau">
                    <thead><tr>
                        <th scope="col">{{ $col['heure'] }}</th><th scope="col">{{ $col['personne'] }}</th><th scope="col">{{ $col['souscription'] }}</th>
                        <th scope="col">{{ $col['moyen'] }}</th><th scope="col">{{ $col['reference'] }}</th><th scope="col" class="text-right">{{ $col['montant'] }}</th>
                    </tr></thead>
                    <tbody>
                        @foreach ($paiements as $p)
                            <tr>
                                <td>{{ $p->date_confirmation->format('H\hi') }}</td>
                                <td>{{ $p->souscription?->user?->name }}</td>
                                <td>{{ $p->souscription?->reference }}</td>
                                <td>{{ $p->canal->label() }}</td>
                                <td>{{ $p->reference_fournisseur ?? '' }}</td>
                                <td class="text-right">{{ format_fcfa($p->montant + $p->frais) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <dl class="bo-definitions mt-3" data-totaux>
                @foreach ($paiements->groupBy(fn ($p) => $p->canal->code) as $groupe)
                    <dt>{{ __('admin.guichet.caisse.total_moyen', ['moyen' => $groupe->first()->canal->label()]) }}</dt><dd>{{ format_fcfa(CaisseGuichet::total($groupe)) }}</dd>
                @endforeach
                <dt><strong>{{ $c['total'] }}</strong></dt><dd><strong data-total>{{ format_fcfa($total) }}</strong></dd>
            </dl>
            <p class="texte-carte mt-3">{{ $c['cloturer_aide'] }}</p>
            <x-admin.confirmation :id="'cloturer-'.$jour" :titre="__('admin.guichet.caisse.cloturer_titre', ['date' => $date])"
                                  :message="__('admin.guichet.caisse.cloturer_confirmation', ['date' => $date, 'nombre' => $paiements->count(), 'total' => format_fcfa($total)])"
                                  :action="route('admin.guichet-caisse.cloturer')" :bouton="$c['cloturer']" icone="cadenas" class="mt-3">
                <x-slot:declencheur>{{ $c['cloturer'] }}</x-slot:declencheur>
                <input type="hidden" name="jour" value="{{ $jour }}">
            </x-admin.confirmation>
        </section>
    @empty
        <section class="bo-carte"><p data-aucun>{{ $c['aucun'] }}</p></section>
    @endforelse
</x-admin.layout>
