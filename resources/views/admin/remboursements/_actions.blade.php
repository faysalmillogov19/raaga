{{-- Action d'une ligne des remboursements : le contrôleur déclare l'exécution (mode, référence de l'opération). --}}
@php($r = __('admin.remboursements'))
<div class="flex justify-end">
    @if ($remboursement->statut === \App\Models\Remboursement::A_EFFECTUER && auth()->user()->can(\App\Enums\Droit::RemboursementsSuivre->value))
        <x-admin.fenetre :id="'execution-'.$remboursement->id" :titre="__('admin.remboursements.actions.declarer_titre', ['reference' => $remboursement->reference])"
                         :action="route('admin.remboursements.execution', $remboursement)" :bouton="$r['actions']['enregistrer']" declencheur-taille="petit">
            <x-slot:declencheur>{{ $r['actions']['declarer'] }}</x-slot:declencheur>
            @if ($remboursement->motif_rejet)
                <x-admin.message type="erreur" class="mb-4">{{ __('admin.remboursements.motif_rejet', ['motif' => $remboursement->motif_rejet]) }}</x-admin.message>
            @endif
            <x-admin.champ nom="mode" :libelle="ucfirst($r['champs']['mode'])" maxlength="40" obligatoire :aide="$r['aides']['mode']" />
            <x-admin.champ nom="reference_operation" :libelle="ucfirst($r['champs']['reference_operation'])" maxlength="100" obligatoire class="mt-4" />
        </x-admin.fenetre>
    @endif
</div>
