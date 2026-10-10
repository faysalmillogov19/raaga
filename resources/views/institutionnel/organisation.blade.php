@php
    use App\Enums\Role as RoleEnum;
    use App\Enums\StatutDocumentKyc;
    use App\Enums\TypeDocumentKyb;

    $o = fn (string $cle, array $p = []) => __('institutionnel.organisation.'.$cle, $p);
    $champs = __('institutionnel.bo.institutions.champs');
    $user = auth()->user();
    $signataire = $user->estSignataire();
@endphp

<x-institutionnel.layout :titre="__('institutionnel.menu.organisation')" :fil="[['libelle' => __('institutionnel.menu.organisation')]]">
    @unless ($signataire)
        <p class="bo-message">{{ $o('lecteur') }}</p>
    @endunless

    <div class="grid gap-[18px] xl:grid-cols-2">
        <section class="bo-carte !mt-0" aria-labelledby="identite-titre">
            <h2 id="identite-titre" class="!text-[1.15rem]">{{ $o('identite') }}</h2>
            <dl class="bo-definitions mt-3">
                @foreach (['denomination', 'sigle', 'forme_juridique', 'rccm', 'ifu', 'adresse', 'secteur'] as $champ)
                    @if ($institution->{$champ})<dt>{{ $champs[$champ] }}</dt><dd>{{ $institution->{$champ} }}</dd>@endif
                @endforeach
                <dt>{{ $o('statut') }}</dt><dd><x-institutionnel.statut :statut="$institution->statut" /></dd>
            </dl>
        </section>

        <section class="bo-carte !mt-0" aria-labelledby="rib-titre">
            <h2 id="rib-titre" class="!text-[1.15rem]">{{ $o('rib') }}</h2>
            @if ($signataire)
                <form method="POST" action="{{ route('institutionnel.organisation.rib') }}" class="mt-3 space-y-4">
                    @csrf
                    @method('PUT')
                    <x-admin.champ nom="rib_titulaire" :libelle="$o('rib_titulaire')" :valeur="$institution->rib_titulaire" required />
                    <x-admin.champ nom="rib_banque" :libelle="$o('rib_banque')" :valeur="$institution->rib_banque" required />
                    <x-admin.champ nom="rib_numero" :libelle="$o('rib_numero')" :valeur="$institution->rib_numero" required />
                    <button type="submit" class="bo-bouton bo-bouton-secondaire">{{ $o('enregistrer') }}</button>
                </form>
            @else
                <dl class="bo-definitions mt-3">
                    <dt>{{ $o('rib_titulaire') }}</dt><dd>{{ $institution->rib_titulaire ?? '—' }}</dd>
                    <dt>{{ $o('rib_banque') }}</dt><dd>{{ $institution->rib_banque ?? '—' }}</dd>
                    <dt>{{ $o('rib_numero') }}</dt><dd>{{ $institution->rib_numero ?? '—' }}</dd>
                </dl>
            @endif
        </section>
    </div>

    <section class="bo-carte" aria-labelledby="dossier-titre">
        <h2 id="dossier-titre" class="!text-[1.15rem]">{{ $o('dossier') }}</h2>
        <p class="texte-carte mt-1">{{ $o('dossier_intro') }}</p>
        <div class="mt-3 overflow-x-auto">
            <table class="bo-tableau">
                <tbody>
                    @foreach (TypeDocumentKyb::cases() as $type)
                        @php($piece = $pieces->get($type->value))
                        <tr>
                            <td class="font-semibold">{{ $type->label() }}</td>
                            <td>
                                @if ($piece)
                                    <x-institutionnel.statut :statut="$piece->statut" />
                                    <span class="texte-carte block">{{ $piece->nom_original }} · {{ $piece->created_at->format('d/m/Y') }}</span>
                                    @if ($piece->statut === StatutDocumentKyc::Rejete)
                                        <span class="block text-rouge">{{ __('institutionnel.motif_rejet', ['motif' => $piece->motif_rejet]) }}</span>
                                    @endif
                                @else
                                    <span class="texte-carte">{{ $o('non_deposee') }}</span>
                                @endif
                            </td>
                            <td>
                                @if ($signataire && $piece?->statut !== StatutDocumentKyc::Valide)
                                    <form method="POST" action="{{ route('institutionnel.organisation.pieces') }}" enctype="multipart/form-data" class="flex flex-wrap items-center gap-2">
                                        @csrf
                                        <input type="hidden" name="type" value="{{ $type->value }}">
                                        <input name="fichier" type="file" accept="application/pdf" required class="max-w-[240px] text-sm" aria-label="{{ $type->label() }}">
                                        <button type="submit" class="bo-bouton bo-bouton-secondaire">{{ $piece ? $o('remplacer') : $o('deposer') }}</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @error('fichier') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
    </section>

    <section class="bo-carte" aria-labelledby="utilisateurs-titre">
        <h2 id="utilisateurs-titre" class="!text-[1.15rem]">{{ $o('utilisateurs') }}</h2>
        <div class="mt-3 overflow-x-auto">
            <table class="bo-tableau">
                <tbody>
                    @foreach ($utilisateurs as $u)
                        <tr>
                            <td class="font-semibold">{{ $u->name }} @if ($u->is($user))<span class="texte-carte">{{ $o('vous') }}</span>@endif</td>
                            <td>{{ $u->email }}</td>
                            <td>{{ __('institutionnel.statuts_role.'.$u->codeRole()->value) }}</td>
                            <td>@include('admin.utilisateurs._statut', ['u' => $u, 'invitation' => ! $u->password])</td>
                            <td>
                                @if ($signataire && ! $u->is($user))
                                    <x-admin.confirmation :id="'u-'.$u->id" :titre="$u->actif ? $o('desactiver') : $o('reactiver')" :message="$u->name"
                                                          :action="route($u->actif ? 'institutionnel.organisation.desactiver' : 'institutionnel.organisation.reactiver', $u)"
                                                          :bouton="$u->actif ? $o('desactiver') : $o('reactiver')" motif :danger="$u->actif">
                                        <x-slot:declencheur>{{ $u->actif ? $o('desactiver') : $o('reactiver') }}</x-slot:declencheur>
                                    </x-admin.confirmation>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($signataire)
            <form method="POST" action="{{ route('institutionnel.organisation.inviter') }}" class="mt-5 grid gap-4 border-t border-ligne pt-4 lg:grid-cols-[1fr_1fr_200px_auto] lg:items-end">
                @csrf
                <x-admin.champ nom="nom" :libelle="$o('nom')" required />
                <x-admin.champ nom="email" :libelle="$o('email')" type="email" required />
                <x-admin.liste nom="role" :libelle="$o('role')" :options="collect(RoleEnum::institutionnels())->mapWithKeys(fn ($r) => [$r->value => __('institutionnel.statuts_role.'.$r->value)])->all()" :valeur="RoleEnum::InstitutionLecteur->value" />
                <button type="submit" class="bo-bouton bo-bouton-principal">{{ $o('inviter') }}</button>
            </form>
        @endif
    </section>
</x-institutionnel.layout>
