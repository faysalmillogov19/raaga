@php
    $a = fn (string $cle, array $p = []) => __('institutionnel.appels.'.$cle, $p);
    $col = __('institutionnel.appels.colonnes');
    $titre = __('institutionnel.menu.appels').' n° '.$appel->numero;
@endphp

<x-institutionnel.layout :titre="$titre" :fil="[['libelle' => __('institutionnel.menu.appels'), 'url' => route('institutionnel.appels')], ['libelle' => 'n° '.$appel->numero]]">
    <div class="grid gap-[18px] xl:grid-cols-2">
        <section class="bo-carte !mt-0" aria-labelledby="appel-titre">
            <h2 id="appel-titre" class="!text-[1.15rem]">{{ $appel->libelle }}</h2>
            <dl class="bo-definitions mt-3">
                <dt>{{ $col['statut'] }}</dt><dd><x-institutionnel.statut :statut="$appel->statut" /></dd>
                <dt>{{ $col['echeance'] }}</dt><dd>{{ $appel->echeance->translatedFormat('j F Y') }}</dd>
                <dt>{{ $col['montant'] }}</dt><dd>{{ format_fcfa($appel->montant) }}</dd>
                <dt>{{ $col['recu'] }}</dt><dd>{{ format_fcfa($appel->montant_recu) }}</dd>
                <dt>{{ $col['reference'] }}</dt><dd class="font-mono font-bold">{{ $appel->reference_virement }}</dd>
                <dt>{{ __('institutionnel.convention.reference') }}</dt><dd>{{ $appel->convention->reference }}</dd>
            </dl>
            <a href="{{ route('institutionnel.appels.avis', $appel) }}" class="bo-bouton bo-bouton-secondaire mt-4">{{ $a('avis') }}</a>
        </section>

        <section class="bo-carte !mt-0" aria-labelledby="declarer-titre">
            <h2 id="declarer-titre" class="!text-[1.15rem]">{{ $a('declarer') }}</h2>
            @if ($appel->estSolde())
                <p class="texte-carte mt-2">{{ __('institutionnel.regles.appel_solde') }}</p>
            @elseif (! auth()->user()->estSignataire())
                <p class="texte-carte mt-2">{{ $a('lecteur') }}</p>
            @else
                <form method="POST" action="{{ route('institutionnel.appels.declarer', $appel) }}" enctype="multipart/form-data" class="mt-3 grid gap-4 sm:grid-cols-2">
                    @csrf
                    <x-admin.champ nom="date_virement" :libelle="$a('date_virement')" type="date" :valeur="now()->toDateString()" required />
                    <x-admin.champ nom="montant" :libelle="$a('montant')" type="number" min="1" :valeur="$appel->resteAPayer()" required />
                    <x-admin.champ nom="reference" :libelle="$a('reference')" :valeur="$appel->reference_virement" :aide="$a('reference_aide', ['reference' => $appel->reference_virement])" required class="sm:col-span-2" />
                    <div class="formulaire-champ !mt-0 sm:col-span-2">
                        <label for="avis">{{ $a('avis_virement') }}</label>
                        <input id="avis" name="avis" type="file" accept="application/pdf" class="champ">
                        @error('avis') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2"><button type="submit" class="bo-bouton bo-bouton-principal">{{ $a('declarer_bouton') }}</button></div>
                </form>
            @endif
        </section>
    </div>

    <section class="bo-carte" aria-labelledby="declarations-titre">
        <h2 id="declarations-titre" class="!text-[1.15rem]">{{ $a('declarations') }}</h2>
        @if ($appel->declarations->isEmpty())
            <p class="texte-carte mt-2">—</p>
        @else
            <div class="mt-3 overflow-x-auto">
                <table class="bo-tableau">
                    <thead><tr><th scope="col">{{ $a('date_virement') }}</th><th scope="col" class="montant">{{ $col['montant'] }}</th><th scope="col">{{ $a('reference') }}</th><th scope="col">{{ $col['statut'] }}</th></tr></thead>
                    <tbody>
                        @foreach ($appel->declarations as $d)
                            <tr>
                                <td>{{ $d->date_virement->format('d/m/Y') }}</td>
                                <td class="montant">{{ format_fcfa($d->montant) }}</td>
                                <td>{{ $d->reference }}</td>
                                <td><x-institutionnel.statut :statut="$d->statut" />
                                    @if ($d->motif_rejet)<span class="texte-carte block">{{ __('institutionnel.motif_rejet', ['motif' => $d->motif_rejet]) }}</span>@endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</x-institutionnel.layout>
