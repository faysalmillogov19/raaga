@php
    use App\Enums\Droit;
    use App\Enums\StatutTypeBoutique;

    $z = __('admin.zones');
    $gerer = auth()->user()->can(Droit::SitesGerer->value);
    $valider = auth()->user()->can(Droit::SitesConfirmer->value);
@endphp

<x-admin.layout :titre="$z['titre']" :fil="[['libelle' => $z['titre']]]">
    {{-- Niveaux : fixes, lecture seule --}}
    <section class="bo-carte !mt-0" aria-labelledby="niveaux-titre">
        <h2 id="niveaux-titre" class="!text-[1.15rem]">{{ $z['niveaux_titre'] }}</h2>
        <p class="texte-carte mt-1">{{ $z['niveaux_aide'] }}</p>
        <div class="mt-3 overflow-x-auto">
            <table class="bo-tableau">
                <thead><tr><th scope="col">{{ $z['colonnes']['code'] }}</th><th scope="col">{{ $z['colonnes']['libelle'] }}</th><th scope="col" class="montant">{{ $z['colonnes']['utilisation'] }}</th></tr></thead>
                <tbody>
                    @foreach ($niveaux as $n)
                        <tr><td class="font-mono font-semibold">{{ $n['niveau']->value }}</td><td>{{ $n['niveau']->label() }}</td>
                            <td class="montant">{{ trans_choice('admin.zones.boutiques', $n['boutiques'], ['n' => format_nombre($n['boutiques'])]) }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    {{-- Zones : gérées par le GESTIONNAIRE, ajout validé par le VALIDATEUR --}}
    <section class="bo-carte" aria-labelledby="zones-titre">
        <h2 id="zones-titre" class="!text-[1.15rem]">{{ $z['zones_titre'] }}</h2>
        <p class="texte-carte mt-1">{{ $z['zones_aide'] }}</p>
        <div class="mt-3 overflow-x-auto">
            <table class="bo-tableau">
                <thead><tr>@foreach ($z['colonnes'] as $cle => $libelle)<th scope="col" @class(['montant' => $cle === 'utilisation'])>{{ $libelle }}</th>@endforeach</tr></thead>
                <tbody>
                    @foreach ($zones as $zone)
                        <tr @class(['opacity-70' => ! $zone->actif])>
                            <td class="font-mono font-semibold">{{ $zone->code }}</td>
                            <td>
                                @if ($gerer)
                                    <form method="POST" action="{{ route('admin.referencement.zones.update', $zone) }}" class="flex flex-wrap items-center gap-2" novalidate>
                                        @csrf @method('PUT')
                                        <label for="zone-{{ $zone->id }}" class="sr-only">{{ $z['colonnes']['libelle'] }} {{ $zone->code }}</label>
                                        <input id="zone-{{ $zone->id }}" name="libelle" value="{{ $zone->libelle }}" maxlength="100" class="champ !w-64">
                                        <button type="submit" class="bo-bouton bo-bouton-secondaire">{{ $z['renommer'] }}</button>
                                    </form>
                                @else
                                    {{ $zone->libelle }}
                                @endif
                                @if ($zone->par_defaut) <span class="bo-etiquette bo-etiquette-neutre">{{ $z['par_defaut'] }}</span>@endif
                            </td>
                            <td>
                                <span @class(['bo-etiquette', 'bo-etiquette-succes' => $zone->statut === StatutTypeBoutique::Valide, 'bo-etiquette-attention' => $zone->statut === StatutTypeBoutique::AValider, 'bo-etiquette-echec' => $zone->statut === StatutTypeBoutique::Rejete])>{{ $zone->statut->label() }}</span>
                                @unless ($zone->actif)<span class="bo-etiquette bo-etiquette-neutre">{{ $z['desactivee'] }}</span>@endunless
                                @if ($zone->motif_rejet)<br><span class="texte-carte">{{ __('admin.parametrage.motif_rejet', ['motif' => $zone->motif_rejet]) }}</span>@endif
                            </td>
                            <td class="montant">{{ trans_choice('admin.zones.capacites', $zone->capacites_count, ['n' => format_nombre($zone->capacites_count)]) }}</td>
                            <td>
                                <div class="flex flex-wrap gap-2">
                                    @if ($valider && $zone->statut === StatutTypeBoutique::AValider)
                                        <x-admin.confirmation id="confirmer-{{ $zone->id }}" :titre="$z['confirmer']" :message="$z['confirmer_message']"
                                                              :action="route('admin.referencement.zones.confirmer', $zone)" :bouton="$z['confirmer']">
                                            <x-slot:declencheur>{{ $z['confirmer'] }}</x-slot:declencheur>
                                        </x-admin.confirmation>
                                        <x-admin.confirmation id="rejeter-{{ $zone->id }}" :titre="$z['rejeter']" :message="$z['rejeter_message']"
                                                              :action="route('admin.referencement.zones.rejeter', $zone)" :bouton="$z['rejeter']" motif danger>
                                            <x-slot:declencheur>{{ $z['rejeter'] }}</x-slot:declencheur>
                                        </x-admin.confirmation>
                                    @endif
                                    @if ($gerer)
                                        <form method="POST" action="{{ route('admin.referencement.zones.'.($zone->actif ? 'desactiver' : 'activer'), $zone) }}">@csrf
                                            <button type="submit" class="bo-bouton bo-bouton-secondaire">{{ $zone->actif ? $z['desactiver'] : $z['activer'] }}</button></form>
                                        @if (! $zone->par_defaut && $zone->capacites_count === 0)
                                            <form method="POST" action="{{ route('admin.referencement.zones.destroy', $zone) }}">@csrf @method('DELETE')
                                                <button type="submit" class="bo-bouton bo-bouton-danger">{{ $z['supprimer'] }}</button></form>
                                        @endif
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @error('motif') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror

        @if ($gerer)
            <form method="POST" action="{{ route('admin.referencement.zones.store') }}" class="mt-4 grid gap-3 lg:grid-cols-[10rem_1fr_auto] lg:items-end" novalidate>
                @csrf
                <x-admin.champ nom="code" :libelle="$z['colonnes']['code']" maxlength="5" required autocomplete="off" :aide="$z['code_aide']" />
                <x-admin.champ nom="libelle" :libelle="$z['colonnes']['libelle']" maxlength="100" required />
                <button type="submit" class="bo-bouton bo-bouton-principal">{{ $z['ajouter'] }}</button>
            </form>
        @endif
    </section>
</x-admin.layout>
