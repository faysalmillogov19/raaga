{{-- Avenants d'un paramétrage. Variables : $avenants, $libelles, $droitConfirmer, $routeConfirmer, $routeRejeter, $objet. --}}
<section class="bo-carte" aria-labelledby="avenants-titre">
    <h2 id="avenants-titre" class="!text-[1.15rem]">{{ __('admin.parametrage.avenants') }}</h2>
    @forelse ($avenants as $avenant)
        <div class="mt-4 rounded-charte border border-ligne p-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="font-bold text-encre">{{ __('admin.parametrage.avenant_numero', ['n' => $avenant->id]) }}
                    <span class="ml-2"><x-admin.badge-statut :statut="$avenant->statut" /></span></p>
                <p class="texte-carte">{{ $avenant->auteur?->name }} · {{ $avenant->created_at?->format('d/m/Y H:i') }}</p>
            </div>
            <p class="mt-1">{{ $avenant->motif }}</p>
            <div class="mt-2">
                <x-admin.differentiel :differentiel="collect($avenant->nouvelle_valeur)->mapWithKeys(fn ($v, $k) => [$k => [$avenant->ancienne_valeur[$k] ?? null, $v]])->all()" :libelles="$libelles" />
            </div>
            @if ($avenant->motif_decision)
                <p class="texte-carte mt-2">{{ __('admin.parametrage.motif') }} : {{ $avenant->motif_decision }} ({{ $avenant->decideur?->name }})</p>
            @endif

            @if ($avenant->statut === \App\Enums\StatutAvenant::EnAttente)
                @can($droitConfirmer)
                    <div class="mt-3 flex flex-wrap gap-3">
                        <x-admin.confirmation id="avenant-confirmer-{{ $avenant->id }}" :titre="__('admin.parametrage.actions.confirmer_avenant')"
                                              :message="__('admin.parametrage.confirmations.confirmer_avenant')"
                                              :action="route($routeConfirmer, [$objet, $avenant])" :bouton="__('admin.parametrage.actions.confirmer_avenant')">
                            <x-slot:declencheur>{{ __('admin.parametrage.actions.confirmer_avenant') }}</x-slot:declencheur>
                        </x-admin.confirmation>
                        <x-admin.confirmation id="avenant-rejeter-{{ $avenant->id }}" :titre="__('admin.parametrage.actions.rejeter_avenant')"
                                              :message="__('admin.parametrage.confirmations.rejeter_avenant')"
                                              :action="route($routeRejeter, [$objet, $avenant])" :bouton="__('admin.parametrage.actions.rejeter_avenant')" motif danger>
                            <x-slot:declencheur>{{ __('admin.parametrage.actions.rejeter_avenant') }}</x-slot:declencheur>
                        </x-admin.confirmation>
                    </div>
                @endcan
            @endif
        </div>
    @empty
        <p class="texte-carte mt-2">{{ __('admin.parametrage.aucun_avenant') }}</p>
    @endforelse
</section>
