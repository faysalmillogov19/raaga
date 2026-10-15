{{--
    Moyens de paiement (RÉGULATEUR : ajout, modification, activation, puis soumission ; VALIDATEUR : validation dans
    « À valider › Données financières ») : tableau de données, fenêtre d'ajout. Jamais de suppression.
--}}
@php
    use App\Http\Controllers\Admin\NiveauxZonesController;

    $p = __('admin.moyens_paiement');
    $gerer = auth()->user()->can(NiveauxZonesController::droit('moyens', 'gerer')->value);
@endphp

<x-admin.layout :titre="$p['titre']" :sous-titre="$p['intro']" :fil="[['libelle' => $p['titre']]]">
    @if ($gerer)
        <x-slot:actions>
            <x-admin.fenetre id="ajouter-moyens" :titre="$p['ajouter']" :action="route('admin.referencement.zones.store', 'moyens')" declencheur-icone="plus" soumission>
                <x-slot:declencheur>{{ $p['ajouter'] }}</x-slot:declencheur>
                @include('admin.moyens-paiement._champs', ['m' => null, 'prefixe' => 'ajout'])
            </x-admin.fenetre>
        </x-slot:actions>
    @endif

    <div class="bo-carte !mt-0">
        <x-tableau :t="$tableau" :libelle="$p['titre']" />
    </div>
</x-admin.layout>
