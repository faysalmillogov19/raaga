{{--
    Fiche d'un moyen de paiement : statut, modification demandée en attente, actions (modifier, activer ou désactiver :
    soumis au VALIDATEUR une fois le moyen confirmé ; valider ou rejeter), usages et plafonds, instructions affichées au
    citoyen, historique des versions. Jamais de suppression.
--}}
@php
    use App\Enums\StatutTypeBoutique;
    use App\Enums\UsageMoyenPaiement;
    use App\Http\Controllers\Admin\NiveauxZonesController;
    use App\Support\AffichageParametres;

    $z = __('admin.zones');
    $p = __('admin.moyens_paiement');
    $gerer = auth()->user()->can(NiveauxZonesController::droit('moyens', 'gerer')->value);
    $titre = $element->code.' · '.$element->libelle;
    $enAttente = $element->statut === StatutTypeBoutique::AValider || $element->aModificationEnAttente();
    $montant = fn (?int $v) => $v === null ? $p['sans_limite'] : format_fcfa($v);
@endphp

<x-admin.layout :titre="$titre" :fil="[['libelle' => $p['titre'], 'url' => route('admin.referencement.moyens')], ['libelle' => $titre]]">
    <section class="bo-carte !mt-0" aria-labelledby="etat-titre">
        <h2 id="etat-titre" class="!text-[1.15rem]">{{ __('admin.parametrage.statut') }} : {{ $element->statut->label() }}
            @unless ($element->actif) · {{ $z['desactive'] }}@endunless
            @if ($element->par_defaut) <x-admin.badge-statut :statut="$z['par_defaut']" variante="neutre" />@endif</h2>
        <p class="texte-carte mt-1">{{ trans_choice('admin.zones.paiements', $utilisations, ['n' => format_nombre($utilisations)]) }} · {{ __('admin.types.version') }} {{ $element->version ?: '' }}</p>
        @if ($element->aModificationEnAttente())
            <x-admin.message type="info" class="mt-4 !mb-0" data-modification-attente>{{ $p['modification_attente'] }}
                {{ collect($element->modification_demandee)->map(fn ($v, $k) => ($p['champs'][$k] ?? $k).' : '.AffichageParametres::valeur($k, $v))->implode(' ; ') }}</x-admin.message>
        @endif
        @if ($element->motif_rejet)
            <x-admin.message type="erreur" class="mt-4 !mb-0">{{ __('admin.parametrage.motif_rejet', ['motif' => $element->motif_rejet]) }}</x-admin.message>
        @endif

        <div class="mt-4 flex flex-wrap gap-3">
            @can(NiveauxZonesController::droit('moyens', 'confirmer')->value)
                @if ($enAttente)
                    <x-admin.confirmation id="confirmer" :titre="$z['confirmer']" :message="$z['confirmer_message']"
                                          :action="route('admin.referencement.zones.confirmer', ['moyens', $element->id])" :bouton="$z['confirmer']">
                        <x-slot:declencheur>{{ $z['confirmer'] }}</x-slot:declencheur>
                    </x-admin.confirmation>
                    <x-admin.confirmation id="rejeter" :titre="$z['rejeter']" :message="$z['rejeter_message']"
                                          :action="route('admin.referencement.zones.rejeter', ['moyens', $element->id])" :bouton="$z['rejeter']" motif danger>
                        <x-slot:declencheur>{{ $z['rejeter'] }}</x-slot:declencheur>
                    </x-admin.confirmation>
                @endif
            @endcan
            @if ($gerer)
                <x-admin.fenetre id="modifier-element" :titre="$p['modifier']" :action="route('admin.referencement.zones.update', ['moyens', $element->id])" methode="PUT"
                                 :bouton="$element->statut === StatutTypeBoutique::Valide ? $p['soumettre_modification'] : $z['enregistrer']" declencheur-variante="secondaire"
                                 :soumission="in_array($element->statut, [StatutTypeBoutique::Brouillon, StatutTypeBoutique::Rejete], true)">
                    <x-slot:declencheur>{{ $p['modifier'] }}</x-slot:declencheur>
                    @include('admin.moyens-paiement._champs', ['m' => $element, 'prefixe' => 'modif'])
                </x-admin.fenetre>
                <x-admin.confirmation id="activation-element" :titre="$element->actif ? $z['desactiver'] : $z['activer']"
                                      :message="$element->actif ? $p['desactiver_message'] : $p['activer_message']"
                                      :action="route('admin.referencement.zones.'.($element->actif ? 'desactiver' : 'activer'), ['moyens', $element->id])"
                                      :bouton="$element->actif ? $z['desactiver'] : $z['activer']">
                    <x-slot:declencheur>{{ $element->actif ? $z['desactiver'] : $z['activer'] }}</x-slot:declencheur>
                </x-admin.confirmation>
            @endif
            {{-- SUPER_ADMIN : mise à jour directe d'un moyen créé par le seeder (par défaut), appliquée sans validation. --}}
            @if ($element->par_defaut && ! $enAttente && auth()->user()->can(\App\Enums\Droit::InstallationMettreAJour->value))
                <x-admin.fenetre id="mettre-a-jour-moyen" :titre="__('admin.parametrage.direct.titre')" :action="route('admin.referencement.zones.direct', ['moyens', $element->id])" methode="PUT"
                                 :bouton="__('admin.parametrage.direct.enregistrer')">
                    <x-slot:declencheur>{{ __('admin.parametrage.direct.bouton') }}</x-slot:declencheur>
                    <p class="texte-carte mb-3">{{ __('admin.parametrage.direct.aide') }}</p>
                    @include('admin.moyens-paiement._champs', ['m' => $element, 'prefixe' => 'direct'])
                    <x-admin.champ nom="actif" id="direct-actif" :libelle="__('admin.parametrage.direct.actif')" type="interrupteur" :valeur="$element->actif" />
                </x-admin.fenetre>
            @endif
        </div>
        @if ($gerer)<p class="texte-carte mt-2">{{ $p['regles']['jamais_supprime'] }}</p>@endif
        @error('motif') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
    </section>

    <section class="bo-carte" aria-labelledby="detail-titre">
        <h2 id="detail-titre" class="!text-[1.15rem]">{{ $z['detail_titre'] }}</h2>
        <dl class="mt-3 grid gap-x-6 gap-y-2 sm:grid-cols-2 xl:grid-cols-4">
            <div><dt class="texte-carte">{{ $z['colonnes']['code'] }}</dt><dd class="font-mono font-semibold">{{ $element->code }}</dd></div>
            <div><dt class="texte-carte">{{ $z['colonnes']['libelle'] }}</dt><dd class="font-semibold">{{ $element->libelle }}</dd></div>
            <div><dt class="texte-carte">{{ $p['colonnes']['famille'] }}</dt><dd class="font-semibold">{{ $element->famille?->label() }}</dd></div>
            <div><dt class="texte-carte">{{ $z['colonnes']['ordre'] }}</dt><dd class="font-semibold">{{ $element->ordre }}</dd></div>
        </dl>
        <div class="mt-4 overflow-x-auto">
            <table class="bo-tableau" data-usages>
                <thead><tr><th scope="col">{{ $p['colonnes']['usages'] }}</th><th scope="col">{{ $p['autorise'] }}</th><th scope="col" class="montant">{{ $p['minimum'] }}</th><th scope="col" class="montant">{{ $p['maximum'] }}</th></tr></thead>
                <tbody>
                    @foreach (UsageMoyenPaiement::cases() as $usage)
                        @php($u = $element->usage($usage))
                        <tr><td>{{ $usage->label() }}</td><td>{{ $u['autorise'] ? $z['oui'] : $z['non'] }}</td>
                            <td class="montant">{{ $montant($u['min']) }}</td><td class="montant">{{ $montant($u['max']) }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <h3 class="mt-4 !text-base">{{ $p['colonnes']['instructions'] }}</h3>
        <p class="mt-1 whitespace-pre-line">{{ $element->instructions ?: $p['aucune_instruction'] }}</p>
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
