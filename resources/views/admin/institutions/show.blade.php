@php
    use App\Enums\Droit;
    use App\Enums\Role as RoleEnum;
    use App\Enums\TypeDocumentKyb;

    $i = __('institutionnel.bo.institutions');
    $o = __('institutionnel.organisation');
@endphp

<x-admin.layout :titre="$institution->denomination" :fil="[['libelle' => $i['titre'], 'url' => route('admin.institutions')], ['libelle' => $institution->nomCourt()]]">
    <div class="grid gap-[18px] xl:grid-cols-2">
        <section class="bo-carte !mt-0" aria-labelledby="identite-titre">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <h2 id="identite-titre" class="!text-[1.15rem]">{{ $o['identite'] }}</h2>
                <x-institutionnel.statut :statut="$institution->statut" />
            </div>
            <dl class="bo-definitions mt-3">
                @foreach (['denomination', 'sigle', 'forme_juridique', 'rccm', 'ifu', 'adresse', 'secteur'] as $champ)
                    <dt>{{ $i['champs'][$champ] }}</dt><dd>{{ $institution->{$champ} ?? '—' }}</dd>
                @endforeach
                <dt>{{ $o['dossier'] }}</dt>
                <dd>{{ __('institutionnel.bo.institutions.pieces', ['valides' => $pieces->where('statut', \App\Enums\StatutDocumentKyc::Valide)->count(), 'total' => count(TypeDocumentKyb::cases())]) }}</dd>
            </dl>
            @if ($institution->motif_suspension)
                <x-admin.message type="erreur" class="mt-3 !mb-0">{{ $institution->motif_suspension }}</x-admin.message>
            @endif
            <div class="mt-4">
                @if ($institution->estSuspendue())
                    <x-admin.confirmation id="reactiver" :titre="$i['reactiver']" :message="$institution->denomination" :action="route('admin.institutions.reactiver', $institution)" :bouton="$i['reactiver']" motif>
                        <x-slot:declencheur>{{ $i['reactiver'] }}</x-slot:declencheur>
                    </x-admin.confirmation>
                @else
                    <x-admin.confirmation id="suspendre" :titre="$i['suspendre']" :message="$i['suspendre_message']" :action="route('admin.institutions.suspendre', $institution)" :bouton="$i['suspendre']" motif danger>
                        <x-slot:declencheur>{{ $i['suspendre'] }}</x-slot:declencheur>
                    </x-admin.confirmation>
                @endif
            </div>
        </section>

        <section class="bo-carte !mt-0" aria-labelledby="interlocuteur-titre">
            <h2 id="interlocuteur-titre" class="!text-[1.15rem]">{{ $i['interlocuteur'] }}</h2>
            @can(Droit::InstitutionsInterlocuteur->value)
                <form method="POST" action="{{ route('admin.institutions.interlocuteur', $institution) }}" class="mt-3 space-y-4">
                    @csrf
                    @method('PUT')
                    @foreach (['interlocuteur_nom' => 'text', 'interlocuteur_email' => 'email', 'interlocuteur_telephone' => 'tel'] as $champ => $type)
                        <x-admin.champ :nom="$champ" :libelle="$i['champs'][$champ]" :type="$type" :valeur="$institution->{$champ}" />
                    @endforeach
                    <x-admin.bouton variante="contour" type="submit">{{ $o['enregistrer'] }}</x-admin.bouton>
                </form>
            @else
                <dl class="bo-definitions mt-3">
                    @foreach (['interlocuteur_nom', 'interlocuteur_email', 'interlocuteur_telephone'] as $champ)
                        <dt>{{ $i['champs'][$champ] }}</dt><dd>{{ $institution->{$champ} ?? '—' }}</dd>
                    @endforeach
                </dl>
            @endcan
        </section>
    </div>

    <section class="bo-carte" aria-labelledby="utilisateurs-titre">
        <h2 id="utilisateurs-titre" class="!text-[1.15rem]">{{ $i['utilisateurs'] }}</h2>
        <div class="mt-3 overflow-x-auto">
            <table class="bo-tableau">
                <tbody>
                    @foreach ($utilisateurs as $u)
                        <tr>
                            <td class="font-semibold">{{ $u->name }}</td>
                            <td>{{ $u->email }}</td>
                            <td>{{ __('institutionnel.statuts_role.'.$u->codeRole()->value) }}</td>
                            <td>@include('admin.utilisateurs._statut', ['u' => $u, 'invitation' => ! $u->password])</td>
                            <td>
                                @if ($u->actif)
                                    <form method="POST" action="{{ route('admin.institutions.lien', [$institution, $u]) }}">
                                        @csrf
                                        <button type="submit" class="lien-action">{{ $i['renvoyer_lien'] }}</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <form method="POST" action="{{ route('admin.institutions.inviter', $institution) }}" class="mt-5 grid gap-4 border-t border-ligne pt-4 lg:grid-cols-[1fr_1fr_200px_auto] lg:items-end">
            @csrf
            <x-admin.champ nom="nom" :libelle="$o['nom']" required />
            <x-admin.champ nom="email" :libelle="$o['email']" type="email" required />
            <x-admin.liste nom="role" :libelle="$o['role']" :options="collect(RoleEnum::institutionnels())->mapWithKeys(fn ($r) => [$r->value => __('institutionnel.statuts_role.'.$r->value)])->all()" :valeur="RoleEnum::InstitutionSignataire->value" />
            <x-admin.bouton variante="principal" type="submit">{{ $i['inviter'] }}</x-admin.bouton>
        </form>
    </section>

    @if ($conventions->isNotEmpty())
        <section class="bo-carte" aria-labelledby="conventions-titre">
            <h2 id="conventions-titre" class="!text-[1.15rem]">{{ __('admin.menu.conventions') }}</h2>
            <ul class="mt-2 space-y-1">
                @foreach ($conventions as $c)
                    <li>{{ $c->reference }} — {{ $c->statut->label() }}{{ $c->estSignee() ? ' — signée le '.$c->signee_le->format('d/m/Y') : '' }}</li>
                @endforeach
            </ul>
        </section>
    @endif
</x-admin.layout>
