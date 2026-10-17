@php
    $code = $utilisateur->codeRole();
    $libellesAudit = __('admin.utilisateurs.audit');
@endphp

<x-admin.layout :titre="$utilisateur->name" :fil="[
    ['libelle' => __('admin.menu.utilisateurs'), 'url' => route('admin.utilisateurs')],
    ['libelle' => $utilisateur->name],
]">
    <div class="grid gap-[18px] xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
        {{-- Identité / compte --}}
        <section class="bo-carte !mt-0" aria-labelledby="identite-titre">
            <h2 id="identite-titre" class="!text-[1.15rem]">{{ __('admin.utilisateurs.fiche.compte') }}</h2>
            <dl class="bo-definitions mt-3">
                    <dt>{{ __('admin.utilisateurs.fiche.email') }}</dt><dd>{{ $utilisateur->email }}</dd>
                    <dt>{{ __('admin.utilisateurs.fiche.role') }}</dt>
                    <dd><span class="bo-badge {{ $code->badgeClasse() }}">{{ $code->badge() }}</span></dd>
                    <dt>{{ __('admin.utilisateurs.fiche.totp') }}</dt><dd>{{ $utilisateur->totpActive() ? __('admin.utilisateurs.totp_active') : __('admin.utilisateurs.totp_inactive') }}</dd>
                    <dt>{{ __('admin.utilisateurs.fiche.derniere_connexion') }}</dt><dd>{{ $utilisateur->derniere_connexion_le?->format('d/m/Y H:i') ?? __('admin.utilisateurs.jamais') }}</dd>
                <dt>{{ __('admin.utilisateurs.fiche.statut') }}</dt>
                <dd>@include('admin.utilisateurs._statut', ['u' => $utilisateur, 'invitation' => $invitationEnAttente])
                    @if ($utilisateur->estVerrouille())
                        <span class="texte-carte block">{{ __('admin.utilisateurs.fiche.verrouille_jusqua', ['heure' => $utilisateur->verrouille_jusqua->format('d/m/Y H:i')]) }}</span>
                    @endif
                </dd>
                <dt>{{ __('admin.utilisateurs.fiche.inscription') }}</dt><dd>{{ $utilisateur->created_at?->format('d/m/Y H:i') }}</dd>
            </dl>
        </section>

        {{-- Actions --}}
        <section class="bo-carte !mt-0" aria-labelledby="actions-titre">
            <h2 id="actions-titre" class="!text-[1.15rem]">{{ __('admin.utilisateurs.fiche.actions') }}</h2>

            @if ($estSoiMeme)
                <p class="texte-carte mt-2">{{ __('admin.utilisateurs.fiche.soi_meme') }}</p>
            @else
                <div class="mt-3 flex flex-wrap gap-3">
                    @if ($utilisateur->actif)
                        <x-admin.confirmation id="role" :titre="__('admin.utilisateurs.actions.changer_role')"
                                              :message="__('admin.utilisateurs.confirmations.role')"
                                              :action="route('admin.utilisateurs.role', $utilisateur)"
                                              :bouton="__('admin.utilisateurs.actions.appliquer_role')">
                            <x-slot:declencheur>{{ __('admin.utilisateurs.actions.changer_role') }}</x-slot:declencheur>
                            <div class="formulaire-champ mt-4">
                                <label for="nouveau-role">{{ __('admin.utilisateurs.fiche.role') }}</label>
                                <select id="nouveau-role" name="role" class="champ">
                                    @foreach ($rolesBackoffice as $r)
                                        <option value="{{ $r->value }}" @selected($r === $code)>{{ $r->badge() }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </x-admin.confirmation>

                        <x-admin.confirmation id="lien" :titre="__('admin.utilisateurs.actions.lien')"
                                              :message="__('admin.utilisateurs.confirmations.lien')"
                                              :action="route('admin.utilisateurs.lien', $utilisateur)"
                                              :bouton="__('admin.utilisateurs.actions.lien')">
                            <x-slot:declencheur>{{ __('admin.utilisateurs.actions.lien') }}</x-slot:declencheur>
                        </x-admin.confirmation>

                        @if ($utilisateur->totpActive())
                            <x-admin.confirmation id="totp" :titre="__('admin.utilisateurs.actions.totp')"
                                                  :message="__('admin.utilisateurs.confirmations.totp')"
                                                  :action="route('admin.utilisateurs.totp', $utilisateur)"
                                                  :bouton="__('admin.utilisateurs.actions.totp')">
                                <x-slot:declencheur>{{ __('admin.utilisateurs.actions.totp') }}</x-slot:declencheur>
                            </x-admin.confirmation>
                        @endif

                        @if ($utilisateur->estVerrouille())
                            <form method="POST" action="{{ route('admin.utilisateurs.deverrouiller', $utilisateur) }}">
                                @csrf
                                <x-admin.bouton variante="contour" type="submit">{{ __('admin.utilisateurs.actions.deverrouiller') }}</x-admin.bouton>
                            </form>
                        @endif
                    @endif

                    @if ($utilisateur->actif)
                        {{-- Carte PERSONNEL : émise ou réémise (l'ancienne est révoquée, nouveau QR code). --}}
                        @php($carte = \App\Services\Cartes\Cartes::courante($utilisateur, \App\Enums\TypeCarte::Personnel))
                        <x-admin.confirmation id="carte" :titre="__('admin.utilisateurs.actions.'.($carte ? 'reemettre_carte' : 'emettre_carte'))"
                                              :message="__('admin.utilisateurs.confirmations.'.($carte ? 'reemettre_carte' : 'emettre_carte'), ['numero' => $carte?->numero])"
                                              :action="route('admin.utilisateurs.carte', $utilisateur)"
                                              :bouton="__('admin.utilisateurs.actions.'.($carte ? 'reemettre_carte' : 'emettre_carte'))">
                            <x-slot:declencheur>{{ __('admin.utilisateurs.actions.'.($carte ? 'reemettre_carte' : 'emettre_carte')) }}</x-slot:declencheur>
                        </x-admin.confirmation>
                        <x-admin.confirmation id="desactiver" :titre="__('admin.utilisateurs.actions.desactiver')"
                                              :message="__('admin.utilisateurs.confirmations.desactiver')"
                                              :action="route('admin.utilisateurs.desactiver', $utilisateur)"
                                              :bouton="__('admin.utilisateurs.actions.desactiver')" motif danger>
                            <x-slot:declencheur>{{ __('admin.utilisateurs.actions.desactiver') }}</x-slot:declencheur>
                        </x-admin.confirmation>
                    @else
                        <x-admin.confirmation id="reactiver" :titre="__('admin.utilisateurs.actions.reactiver')"
                                              :message="__('admin.utilisateurs.confirmations.reactiver')"
                                              :action="route('admin.utilisateurs.reactiver', $utilisateur)"
                                              :bouton="__('admin.utilisateurs.actions.reactiver')" motif>
                            <x-slot:declencheur>{{ __('admin.utilisateurs.actions.reactiver') }}</x-slot:declencheur>
                        </x-admin.confirmation>
                    @endif
                </div>
                @error('motif')
                    <p class="message-erreur" role="alert">{{ $message }}</p>
                @enderror
            @endif
        </section>
    </div>

    {{-- Connexions --}}
    <section class="bo-carte" aria-labelledby="secondaire-titre">
        <h2 id="secondaire-titre" class="!text-[1.15rem]">{{ __('admin.utilisateurs.fiche.connexions') }}</h2>
        @if ($connexions->isEmpty())
            <p class="texte-carte mt-2">{{ __('admin.utilisateurs.fiche.aucune_connexion') }}</p>
        @else
            <div class="mt-3 overflow-x-auto">
                <table class="bo-tableau">
                    <thead><tr><th scope="col">{{ __('admin.connexions.date') }}</th><th scope="col">{{ __('admin.connexions.etape') }}</th><th scope="col">{{ __('admin.connexions.resultat') }}</th><th scope="col">{{ __('admin.connexions.motif') }}</th><th scope="col">{{ __('admin.connexions.ip') }}</th></tr></thead>
                    <tbody>
                        @foreach ($connexions as $c)
                            <tr>
                                <td class="whitespace-nowrap">{{ \Carbon\Carbon::parse($c->created_at)->format('d/m/Y H:i:s') }}</td>
                                <td>{{ __('admin.connexions.etapes.'.$c->etape) }}</td>
                                <td><span @class(['bo-etiquette', 'bo-etiquette-succes' => $c->succes, 'bo-etiquette-echec' => ! $c->succes])>{{ $c->succes ? __('admin.connexions.succes') : __('admin.connexions.echec') }}</span></td>
                                <td>{{ $c->motif ? __('admin.connexions.motifs.'.$c->motif) : '—' }}</td>
                                <td>{{ $c->ip }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{-- Historique (journal d'audit) --}}
    <section class="bo-carte" aria-labelledby="historique-titre">
        <h2 id="historique-titre" class="!text-[1.15rem]">{{ __('admin.utilisateurs.fiche.historique') }}</h2>
        @if ($historique->isEmpty())
            <p class="texte-carte mt-2">{{ __('admin.utilisateurs.fiche.aucun_historique') }}</p>
        @else
            <div class="mt-3 overflow-x-auto">
                <table class="bo-tableau">
                    <tbody>
                        @foreach ($historique as $entree)
                            <tr>
                                <td class="whitespace-nowrap">{{ $entree->created_at?->format('d/m/Y H:i') }}</td>
                                <td>{{ $libellesAudit[$entree->action] ?? $entree->action }}</td>
                                <td>{{ $entree->acteur?->name ?? __('admin.utilisateurs.fiche.acteur_systeme') }}</td>
                                <td class="texte-carte">
                                    @if (isset($entree->ancienne_valeur['role'], $entree->nouvelle_valeur['role']))
                                        {{ \App\Enums\Role::tryFrom($entree->ancienne_valeur['role'])?->badge() }} → {{ \App\Enums\Role::tryFrom($entree->nouvelle_valeur['role'])?->badge() }}
                                    @elseif (isset($entree->nouvelle_valeur['motif']))
                                        {{ $entree->nouvelle_valeur['motif'] }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</x-admin.layout>
