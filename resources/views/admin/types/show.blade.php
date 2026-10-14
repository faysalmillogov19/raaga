@php
    use App\Enums\Droit;
    use App\Enums\StatutTypeBoutique;

    $t = __('admin.types');
    $gerer = auth()->user()->can(Droit::SitesGerer->value);
    $titre = ($type->code ? $type->code.' · ' : '').$type->libelle;
    $enAttente = $type->statut === StatutTypeBoutique::AValider || $type->attribuable_demande !== null;
@endphp

<x-admin.layout :titre="$titre" :fil="[['libelle' => __('admin.sites.titre'), 'url' => route('admin.sites')], ['libelle' => $t['titre'], 'url' => route('admin.sites.types')], ['libelle' => $titre]]">
    {{-- État et décisions --}}
    <section class="bo-carte !mt-0" aria-labelledby="etat-titre">
        <h2 id="etat-titre" class="!text-[1.15rem]">{{ __('admin.parametrage.statut') }} : {{ $type->statut->label() }}
            @unless ($type->actif) · {{ $t['desactive'] }}@endunless
            @if ($type->par_defaut) <span class="bo-etiquette bo-etiquette-neutre">{{ $t['par_defaut'] }}</span>@endif</h2>
        <dl class="mt-3 grid gap-x-6 gap-y-2 sm:grid-cols-2 xl:grid-cols-4">
            <div><dt class="texte-carte">{{ $t['champs']['attribuable'] }}</dt><dd class="font-semibold">{{ $type->attribuable ? $t['oui'] : $t['non'] }}
                @if ($type->attribuable_demande !== null)<br><span class="bo-etiquette bo-etiquette-attention">{{ $type->attribuable_demande ? $t['demande_oui'] : $t['demande_non'] }}</span>@endif</dd></div>
            <div><dt class="texte-carte">{{ $t['colonnes']['utilise_par'] }}</dt><dd class="font-semibold">{{ trans_choice('admin.types.boutiques', $type->boutiques_count, ['n' => format_nombre($type->boutiques_count)]) }}</dd></div>
            <div><dt class="texte-carte">{{ $t['version'] }}</dt><dd class="font-semibold">{{ $type->version ?: '—' }}</dd></div>
        </dl>
        @if ($type->motif_rejet)
            <p class="bo-message bo-message-erreur mt-4 !mb-0" role="alert">{{ __('admin.parametrage.motif_rejet', ['motif' => $type->motif_rejet]) }}</p>
        @endif

        <div class="mt-4 flex flex-wrap gap-3">
            @can(Droit::SitesConfirmer->value)
                @if ($enAttente)
                    <x-admin.confirmation id="confirmer" :titre="$t['actions']['confirmer']" :message="$t['confirmations']['confirmer']"
                                          :action="route('admin.sites.types.confirmer', $type)" :bouton="$t['actions']['confirmer']">
                        <x-slot:declencheur>{{ $t['actions']['confirmer'] }}</x-slot:declencheur>
                    </x-admin.confirmation>
                    <x-admin.confirmation id="rejeter" :titre="$t['actions']['rejeter']" :message="$t['confirmations']['rejeter']"
                                          :action="route('admin.sites.types.rejeter', $type)" :bouton="$t['actions']['rejeter']" motif danger>
                        <x-slot:declencheur>{{ $t['actions']['rejeter'] }}</x-slot:declencheur>
                    </x-admin.confirmation>
                @endif
            @endcan
            @if ($gerer)
                @if ($type->statut === StatutTypeBoutique::Rejete)
                    <form method="POST" action="{{ route('admin.sites.types.soumettre', $type) }}">@csrf
                        <button type="submit" class="bo-bouton bo-bouton-principal">{{ $t['actions']['soumettre'] }}</button></form>
                @endif
                <form method="POST" action="{{ route('admin.sites.types.'.($type->actif ? 'desactiver' : 'activer'), $type) }}">@csrf
                    <button type="submit" class="bo-bouton bo-bouton-secondaire">{{ $type->actif ? $t['actions']['desactiver'] : $t['actions']['activer'] }}</button></form>
                @if (! $type->par_defaut && ! $utilise)
                    <form method="POST" action="{{ route('admin.sites.types.destroy', $type) }}">@csrf @method('DELETE')
                        <button type="submit" class="bo-bouton bo-bouton-danger">{{ $t['actions']['supprimer'] }}</button></form>
                @endif
            @endif
        </div>
        @error('motif') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
    </section>

    @if ($gerer)
        {{-- Libellé, description, ordre (libres) --}}
        <section class="bo-carte" aria-labelledby="modif-titre">
            <h2 id="modif-titre" class="!text-[1.15rem]">{{ $t['modifier_titre'] }}</h2>
            <form method="POST" action="{{ route('admin.sites.types.update', $type) }}" class="mt-3 grid gap-4 lg:grid-cols-3" novalidate>
                @csrf @method('PUT')
                <div class="formulaire-champ !mt-0"><span class="block font-semibold">{{ $t['champs']['code'] }}</span>
                    <p class="font-mono text-lg font-bold">{{ $type->code ?? '—' }}</p><p class="aide-champ">{{ $t['code_fige'] }}</p></div>
                <x-admin.champ nom="libelle" :libelle="$t['champs']['libelle']" :valeur="$type->libelle" maxlength="80" required />
                <x-admin.champ nom="ordre" :libelle="$t['champs']['ordre']" :valeur="$type->ordre" type="number" min="0" max="9999" required />
                <x-admin.champ nom="description" :libelle="$t['champs']['description']" :valeur="$type->description" maxlength="300" class="lg:col-span-2" />
                <div><button type="submit" class="bo-bouton bo-bouton-principal">{{ __('admin.tranches.formulaire.enregistrer') }}</button></div>
            </form>
        </section>

        {{-- « Attribuable par tirage » : changement soumis au VALIDATEUR --}}
        <section class="bo-carte" aria-labelledby="attribuable-titre">
            <h2 id="attribuable-titre" class="!text-[1.15rem]">{{ $t['champs']['attribuable'] }}</h2>
            <p class="texte-carte mt-1">{{ $t['attribuable_aide'] }}</p>
            <form method="POST" action="{{ route('admin.sites.types.attribuable', $type) }}" class="mt-3 flex flex-wrap items-end gap-3" novalidate>
                @csrf
                <input type="hidden" name="attribuable" value="{{ $type->attribuable ? 0 : 1 }}">
                <button type="submit" class="bo-bouton bo-bouton-secondaire" @disabled($type->attribuable_demande !== null)>{{ $type->attribuable ? $t['actions']['rendre_non_attribuable'] : $t['actions']['rendre_attribuable'] }}</button>
            </form>
        </section>
    @endif

    {{-- Historique des versions --}}
    <section class="bo-carte" aria-labelledby="historique-titre">
        <h2 id="historique-titre" class="!text-[1.15rem]">{{ $t['historique'] }}</h2>
        <div class="mt-3 overflow-x-auto">
            <table class="bo-tableau">
                <thead><tr><th scope="col">{{ $t['version'] }}</th><th scope="col">{{ $t['date'] }}</th><th scope="col">{{ $t['auteur'] }}</th><th scope="col">{{ $t['motif'] }}</th><th scope="col">{{ $t['etat'] }}</th></tr></thead>
                <tbody>
                    @forelse ($versions as $v)
                        @php($p = $v->parametres)
                        <tr>
                            <td>{{ $v->numero }}</td>
                            <td class="whitespace-nowrap">{{ $v->created_at->format('d/m/Y H:i') }}</td>
                            <td>{{ $v->auteur?->name ?? $t['systeme'] }}</td>
                            <td>{{ $v->motif }}</td>
                            <td class="text-sm">{{ $p['libelle'] }} · {{ $p['attribuable'] ? $t['attribuable_court'] : $t['non_attribuable_court'] }}{{ $p['actif'] ? '' : ' · '.$t['desactive'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5">{{ $t['aucune_version'] }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</x-admin.layout>
