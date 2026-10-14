@php
    $a = __('institutionnel.bo.appels');
    $col = $a['colonnes'];
@endphp

<x-admin.layout :titre="$a['titre']" :fil="[['libelle' => $a['titre']]]">
    <section class="bo-carte !mt-0" aria-labelledby="rapprocher-titre">
        <h2 id="rapprocher-titre" class="!text-[1.15rem]">{{ $a['a_rapprocher'] }}</h2>
        @if ($aRapprocher->isEmpty())
            <p class="texte-carte mt-2">{{ $a['aucun_a_rapprocher'] }}</p>
        @else
            <div class="mt-3 overflow-x-auto">
                <table class="bo-tableau">
                    <thead><tr><th scope="col">{{ $col['institution'] }}</th><th scope="col">{{ $col['appel'] }}</th><th scope="col">{{ $col['declare'] }}</th><th scope="col" class="montant">{{ $col['montant'] }}</th><th scope="col">{{ $col['reference'] }}</th></tr></thead>
                    <tbody>
                        @foreach ($aRapprocher as $d)
                            <tr>
                                <td>{{ $d->appel->institution->nomCourt() }}</td>
                                <td><a href="{{ route('admin.appels-fonds.show', $d->appel) }}" class="font-bold text-vert-fonce underline underline-offset-2">{{ $d->appel->convention->reference }} · n° {{ $d->appel->numero }}</a></td>
                                <td class="whitespace-nowrap">{{ $d->date_virement->format('d/m/Y') }}</td>
                                <td class="montant">{{ format_fcfa($d->montant) }}</td>
                                <td class="font-mono">{{ $d->reference }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="bo-carte" aria-labelledby="tous-titre">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 id="tous-titre" class="!text-[1.15rem]">{{ $a['tous'] }}</h2>
            <form method="GET" class="flex items-center gap-2">
                <select name="statut" class="champ" aria-label="{{ $col['statut'] }}" onchange="this.form.submit()">
                    <option value="">{{ __('admin.utilisateurs.tous') }}</option>
                    @foreach (\App\Enums\StatutAppelFonds::cases() as $s)
                        <option value="{{ $s->value }}" @selected(request('statut') === $s->value)>{{ $s->label() }}</option>
                    @endforeach
                </select>
            </form>
        </div>
        <div class="mt-3 overflow-x-auto">
            <table class="bo-tableau">
                <thead><tr><th scope="col">{{ $col['institution'] }}</th><th scope="col">{{ $col['appel'] }}</th><th scope="col">{{ $col['echeance'] }}</th><th scope="col" class="montant">{{ $col['montant'] }}</th><th scope="col" class="montant">{{ $col['recu'] }}</th><th scope="col">{{ $col['statut'] }}</th></tr></thead>
                <tbody>
                    @forelse ($appels as $appel)
                        <tr>
                            <td>{{ $appel->institution->nomCourt() }}</td>
                            <td><a href="{{ route('admin.appels-fonds.show', $appel) }}" class="font-bold text-vert-fonce underline underline-offset-2">{{ $appel->convention->reference }} · n° {{ $appel->numero }}</a></td>
                            <td class="whitespace-nowrap">{{ $appel->echeance->format('d/m/Y') }}</td>
                            <td class="montant">{{ format_fcfa($appel->montant) }}</td>
                            <td class="montant">{{ format_fcfa($appel->montant_recu) }}</td>
                            <td><x-institutionnel.statut :statut="$appel->statut" /></td>
                        </tr>
                    @empty
                        <tr><td colspan="6">{{ $a['aucun'] }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $appels->links('admin._pagination') }}</div>
    </section>
</x-admin.layout>
