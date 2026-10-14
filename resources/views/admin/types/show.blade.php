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
            @if ($type->par_defaut) <x-admin.badge-statut :statut="$t['par_defaut']" variante="neutre" />@endif</h2>
        <dl class="mt-3 grid gap-x-6 gap-y-2 sm:grid-cols-2 xl:grid-cols-4">
            <div><dt class="texte-carte">{{ $t['champs']['attribuable'] }}</dt><dd class="font-semibold">{{ $type->attribuable ? $t['oui'] : $t['non'] }}
                @if ($type->attribuable_demande !== null)<br><x-admin.badge-statut :statut="$type->attribuable_demande ? $t['demande_oui'] : $t['demande_non']" variante="avertissement" />@endif</dd></div>
            <div><dt class="texte-carte">{{ $t['colonnes']['utilise_par'] }}</dt><dd class="font-semibold">{{ trans_choice('admin.types.boutiques', $type->boutiques_count, ['n' => format_nombre($type->boutiques_count)]) }}</dd></div>
            <div><dt class="texte-carte">{{ $t['version'] }}</dt><dd class="font-semibold">{{ $type->version ?: '—' }}</dd></div>
        </dl>
        @if ($type->motif_rejet)
            <x-admin.message type="erreur" class="mt-4 !mb-0">{{ __('admin.parametrage.motif_rejet', ['motif' => $type->motif_rejet]) }}</x-admin.message>
        @endif

        <div class="mt-4 flex flex-wrap gap-3">
            @can(Droit::SitesConfirmer->value)
                @if ($enAttente)
                    <x-admin.confirmation etape="confirmer" id="confirmer" :titre="$t['actions']['confirmer']" :message="$t['confirmations']['confirmer']"
                                          :action="route('admin.sites.types.confirmer', $type)" :bouton="$t['actions']['confirmer']">
                        <x-slot:declencheur>{{ $t['actions']['confirmer'] }}</x-slot:declencheur>
                    </x-admin.confirmation>
                    <x-admin.confirmation etape="rejeter" id="rejeter" :titre="$t['actions']['rejeter']" :message="$t['confirmations']['rejeter']"
                                          :action="route('admin.sites.types.rejeter', $type)" :bouton="$t['actions']['rejeter']" motif danger>
                        <x-slot:declencheur>{{ $t['actions']['rejeter'] }}</x-slot:declencheur>
                    </x-admin.confirmation>
                @endif
            @endcan
            @if ($gerer)
                <x-admin.fenetre id="modifier-type" :titre="$t['modifier_titre']" :action="route('admin.sites.types.update', $type)" methode="PUT"
                                 :bouton="__('admin.composants.circuit.brouillon')" declencheur-variante="secondaire">
                    <x-slot:declencheur>{{ $t['modifier_titre'] }}</x-slot:declencheur>
                    <div class="adm-fenetre-grille">
                        <x-admin.champ nom="code_affiche" :libelle="$t['champs']['code']" type="verrouille" :valeur="$type->code ?? '—'" :aide="$t['code_fige']" />
                        <x-admin.champ nom="libelle" :libelle="$t['champs']['libelle']" :valeur="$type->libelle" maxlength="80" required />
                        <x-admin.champ nom="description" :libelle="$t['champs']['description']" :valeur="$type->description" maxlength="300" class="adm-plein" />
                        <x-admin.champ nom="ordre" :libelle="$t['champs']['ordre']" :valeur="$type->ordre" type="number" min="0" max="9999" required />
                    </div>
                </x-admin.fenetre>
                @if ($type->statut === StatutTypeBoutique::Rejete)
                    <x-admin.confirmation etape="soumettre" id="soumettre-type" :titre="$t['actions']['soumettre']" :message="$t['confirmations']['soumettre']"
                                          :action="route('admin.sites.types.soumettre', $type)" />
                @endif
                @if ($type->attribuable_demande === null)
                    <x-admin.confirmation id="attribuable-type" :titre="$type->attribuable ? $t['actions']['rendre_non_attribuable'] : $t['actions']['rendre_attribuable']"
                                          :message="$t['attribuable_aide'].' '.$t['confirmations']['attribuable']" :action="route('admin.sites.types.attribuable', $type)"
                                          :bouton="$type->attribuable ? $t['actions']['rendre_non_attribuable'] : $t['actions']['rendre_attribuable']">
                        <x-slot:declencheur>{{ $type->attribuable ? $t['actions']['rendre_non_attribuable'] : $t['actions']['rendre_attribuable'] }}</x-slot:declencheur>
                        <input type="hidden" name="attribuable" value="{{ $type->attribuable ? 0 : 1 }}">
                    </x-admin.confirmation>
                @endif
                <x-admin.confirmation id="activation-type" :titre="$type->actif ? $t['actions']['desactiver'] : $t['actions']['activer']"
                                      :message="$t['confirmations'][$type->actif ? 'desactiver' : 'activer']"
                                      :action="route('admin.sites.types.'.($type->actif ? 'desactiver' : 'activer'), $type)" :bouton="$type->actif ? $t['actions']['desactiver'] : $t['actions']['activer']">
                    <x-slot:declencheur>{{ $type->actif ? $t['actions']['desactiver'] : $t['actions']['activer'] }}</x-slot:declencheur>
                </x-admin.confirmation>
                @if (! $type->par_defaut && ! $utilise)
                    <x-admin.confirmation id="supprimer-type" :titre="$t['actions']['supprimer']" :message="$t['confirmations']['supprimer']"
                                          :action="route('admin.sites.types.destroy', $type)" methode="DELETE" :bouton="$t['actions']['supprimer']" danger>
                        <x-slot:declencheur>{{ $t['actions']['supprimer'] }}</x-slot:declencheur>
                    </x-admin.confirmation>
                @endif
            @endif
        </div>
        @error('motif') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
    </section>

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
