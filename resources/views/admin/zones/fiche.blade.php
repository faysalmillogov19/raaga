@php
    use App\Enums\StatutTypeBoutique;
    use App\Http\Controllers\Admin\NiveauxZonesController;

    $z = __('admin.zones');
    $niveaux = $liste === 'niveaux';
    $unites = $liste === 'unites';
    $gerer = auth()->user()->can(NiveauxZonesController::droit($liste, 'gerer')->value);
    $titre = $element->code.' · '.$element->libelle;
    $supprimable = ! $element->par_defaut && $utilisations === 0;
@endphp

<x-admin.layout :titre="$titre" :fil="[['libelle' => $z[$liste.'_titre'], 'url' => route('admin.referencement.'.$liste)], ['libelle' => $titre]]">
    <section class="bo-carte !mt-0" aria-labelledby="etat-titre">
        <h2 id="etat-titre" class="!text-[1.15rem]">{{ __('admin.parametrage.statut') }} : {{ $element->statut->label() }}
            @unless ($element->actif) · {{ $z['desactive'] }}@endunless
            @if ($element->par_defaut) <x-admin.badge-statut :statut="$z['par_defaut']" variante="neutre" />@endif</h2>
        <p class="texte-carte mt-1">{{ trans_choice('admin.zones.utilisations', $utilisations, ['n' => format_nombre($utilisations)]) }} · {{ __('admin.types.version') }} {{ $element->version ?: '—' }}</p>
        @if ($element->motif_rejet)
            <x-admin.message type="erreur" class="mt-4 !mb-0">{{ __('admin.parametrage.motif_rejet', ['motif' => $element->motif_rejet]) }}</x-admin.message>
        @endif

        <div class="mt-4 flex flex-wrap gap-3">
            @can(NiveauxZonesController::droit($liste, 'confirmer')->value)
                @if ($element->statut === StatutTypeBoutique::AValider)
                    <x-admin.confirmation id="confirmer" :titre="$z['confirmer']" :message="$z['confirmer_message']"
                                          :action="route('admin.referencement.zones.confirmer', [$liste, $element->id])" :bouton="$z['confirmer']">
                        <x-slot:declencheur>{{ $z['confirmer'] }}</x-slot:declencheur>
                    </x-admin.confirmation>
                    <x-admin.confirmation id="rejeter" :titre="$z['rejeter']" :message="$z['rejeter_message']"
                                          :action="route('admin.referencement.zones.rejeter', [$liste, $element->id])" :bouton="$z['rejeter']" motif danger>
                        <x-slot:declencheur>{{ $z['rejeter'] }}</x-slot:declencheur>
                    </x-admin.confirmation>
                @endif
            @endcan
            @if ($gerer)
                <x-admin.fenetre id="modifier-element" :titre="$z['modifier_titre']" :action="route('admin.referencement.zones.update', [$liste, $element->id])" methode="PUT"
                                 :bouton="$z['enregistrer']" declencheur-variante="secondaire"
                                 :soumission="in_array($element->statut, [\App\Enums\StatutTypeBoutique::Brouillon, \App\Enums\StatutTypeBoutique::Rejete], true)">
                    <x-slot:declencheur>{{ $z['modifier_titre'] }}</x-slot:declencheur>
                    <div class="adm-fenetre-grille">
                        <x-admin.champ nom="code_affiche" :libelle="$z['colonnes']['code']" type="verrouille" :valeur="$element->code" :aide="$z['code_fige']" />
                        <x-admin.champ nom="libelle" :libelle="$z['colonnes']['libelle']" :valeur="$element->libelle" maxlength="100" required />
                        @if ($niveaux)
                            <x-admin.champ nom="rang" :libelle="$z['colonnes']['rang']" :valeur="$element->rang" type="number" min="-9" max="99" required :aide="$z['rang_aide']" />
                        @else
                            @unless ($unites)
                                <x-admin.champ nom="ordre" :libelle="$z['colonnes']['ordre']" :valeur="$element->ordre" type="number" min="0" max="9999" />
                            @endunless
                            <x-admin.champ nom="description" :libelle="$z['colonnes']['description']" :valeur="$element->description" maxlength="300" class="adm-plein" />
                        @endif
                    </div>
                </x-admin.fenetre>
                <x-admin.confirmation id="activation-element" :titre="$element->actif ? $z['desactiver'] : $z['activer']"
                                      :message="$element->actif ? $z['desactiver_message'] : $z['activer_message']"
                                      :action="route('admin.referencement.zones.'.($element->actif ? 'desactiver' : 'activer'), [$liste, $element->id])"
                                      :bouton="$element->actif ? $z['desactiver'] : $z['activer']">
                    <x-slot:declencheur>{{ $element->actif ? $z['desactiver'] : $z['activer'] }}</x-slot:declencheur>
                </x-admin.confirmation>
                @if ($supprimable)
                    <x-admin.confirmation id="supprimer-element" :titre="$z['supprimer'].' '.$element->code" :message="$z['confirmer_suppression']"
                                          :action="route('admin.referencement.zones.destroy', [$liste, $element->id])" methode="DELETE" :bouton="$z['supprimer']" danger>
                        <x-slot:declencheur>{{ $z['supprimer'] }}</x-slot:declencheur>
                    </x-admin.confirmation>
                @endif
            @endif
            {{-- SUPER_ADMIN : élément créé par le seeder (par défaut), mis à jour directement sans validation. --}}
            @if ($element->par_defaut && $element->statut !== StatutTypeBoutique::AValider && ! $element->aModificationEnAttente()
                && auth()->user()->can(\App\Enums\Droit::InstallationMettreAJour->value))
                <x-admin.fenetre id="mettre-a-jour-element" :titre="__('admin.parametrage.direct.titre')" :action="route('admin.referencement.zones.direct', [$liste, $element->id])" methode="PUT"
                                 :bouton="__('admin.parametrage.direct.enregistrer')">
                    <x-slot:declencheur>{{ __('admin.parametrage.direct.bouton') }}</x-slot:declencheur>
                    <p class="texte-carte mb-3">{{ __('admin.parametrage.direct.aide') }}</p>
                    <div class="adm-fenetre-grille">
                        <x-admin.champ nom="code_affiche" id="direct-code" :libelle="$z['colonnes']['code']" type="verrouille" :valeur="$element->code" :aide="$z['code_fige']" />
                        <x-admin.champ nom="libelle" id="direct-libelle" :libelle="$z['colonnes']['libelle']" :valeur="$element->libelle" maxlength="100" required />
                        @if ($niveaux)
                            <x-admin.champ nom="rang" id="direct-rang" :libelle="$z['colonnes']['rang']" :valeur="$element->rang" type="number" min="-9" max="99" required :aide="$z['rang_aide']" />
                        @else
                            @unless ($unites)
                                <x-admin.champ nom="ordre" id="direct-ordre" :libelle="$z['colonnes']['ordre']" :valeur="$element->ordre" type="number" min="0" max="9999" />
                            @endunless
                            <x-admin.champ nom="description" id="direct-description" :libelle="$z['colonnes']['description']" :valeur="$element->description" maxlength="300" class="adm-plein" />
                        @endif
                        <x-admin.champ nom="actif" id="direct-actif" :libelle="__('admin.parametrage.direct.actif_element')" type="interrupteur" :valeur="$element->actif" />
                    </div>
                </x-admin.fenetre>
            @endif
        </div>
        @if ($gerer && ! $supprimable)<p class="texte-carte mt-2">{{ $element->par_defaut ? $z['regles']['defaut'] : $z['regles']['utilisee'] }}</p>@endif
        @error('motif') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
    </section>

    <section class="bo-carte" aria-labelledby="modif-titre">
        <h2 id="modif-titre" class="!text-[1.15rem]">{{ $z['detail_titre'] }}</h2>
        <dl class="mt-3 grid gap-x-6 gap-y-2 sm:grid-cols-2 xl:grid-cols-4">
            <div><dt class="texte-carte">{{ $z['colonnes']['code'] }}</dt><dd class="font-mono font-semibold">{{ $element->code }}</dd></div>
            <div><dt class="texte-carte">{{ $z['colonnes']['libelle'] }}</dt><dd class="font-semibold">{{ $element->libelle }}</dd></div>
            @unless ($unites)<div><dt class="texte-carte">{{ $z['colonnes'][$niveaux ? 'rang' : 'ordre'] }}</dt><dd class="font-semibold">{{ $niveaux ? $element->rang : $element->ordre }}</dd></div>@endunless
            @unless ($niveaux)<div><dt class="texte-carte">{{ $z['colonnes']['description'] }}</dt><dd class="font-semibold">{{ $element->description ?? '—' }}</dd></div>@endunless
        </dl>
    </section>

    <section class="bo-carte" aria-labelledby="historique-titre">
        <h2 id="historique-titre" class="!text-[1.15rem]">{{ __('admin.types.historique') }}</h2>
        <div class="mt-3 overflow-x-auto">
            <table class="bo-tableau">
                <thead><tr><th scope="col">{{ __('admin.types.version') }}</th><th scope="col">{{ __('admin.types.date') }}</th><th scope="col">{{ __('admin.types.auteur') }}</th><th scope="col">{{ __('admin.types.motif') }}</th><th scope="col">{{ __('admin.types.etat') }}</th></tr></thead>
                <tbody>
                    @forelse ($versions as $v)
                        <tr>
                            <td>{{ $v->numero }}</td>
                            <td class="whitespace-nowrap">{{ $v->created_at->format('d/m/Y H:i') }}</td>
                            <td>{{ $v->auteur?->name ?? __('admin.types.systeme') }}</td>
                            <td>{{ $v->motif }}</td>
                            <td class="text-sm">{{ $v->parametres['libelle'] ?? '' }}{{ ($v->parametres['actif'] ?? true) ? '' : ' · '.$z['desactive'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5">{{ __('admin.types.aucune_version') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</x-admin.layout>
