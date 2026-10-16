{{--
    Dossier de paiement : informations, paiements (contrôle d'un paiement manuel ; correction d'un paiement confirmé,
    confirmée par le VALIDATEUR), relances tracées (date, canal, auteur ou « automatique »), remboursements.
--}}
@php
    use App\Enums\Droit;
    use App\Enums\StatutPaiement;
    use App\Models\CorrectionPaiement;
    use App\Models\Paiement;

    $p = __('admin.paiements');
    $d = $p['dossier'];
    $user = auth()->user();
    $rapprocher = $user->can(Droit::SouscriptionsRapprocher->value);
    $vue = $type === 'souscription' ? 'souscriptions' : 'cautions';
    $titre = __('admin.paiements.dossier.titre', ['reference' => $objet->reference]);
@endphp

<x-admin.layout :titre="$titre" :fil="[['libelle' => $p['titre'], 'url' => route('admin.paiements', ['vue' => $vue])], ['libelle' => $objet->reference]]">
    <section class="bo-carte !mt-0" aria-labelledby="infos-titre">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 id="infos-titre" class="!text-[1.15rem]">{{ $d['informations'] }}</h2>
            @if ($user->can(Droit::RelancesEnvoyer->value) && $objet->attendPaiement())
                <x-admin.fenetre id="relancer" :titre="__('admin.paiements.actions.relancer_titre', ['citoyen' => $objet->user?->name])"
                                 :action="route('admin.paiements.relance', [$type, $objet->id])" :bouton="$p['actions']['envoyer']" declencheur-variante="secondaire">
                    <x-slot:declencheur>{{ $p['actions']['relancer'] }}</x-slot:declencheur>
                    <p class="texte-carte mb-4">{{ $p['aides']['relance'] }}</p>
                    <x-admin.champ nom="message" :libelle="ucfirst($p['champs']['message'])" type="zone" maxlength="500" />
                </x-admin.fenetre>
            @endif
        </div>
        <dl class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3" data-dossier>
            <div><dt class="texte-carte">{{ $d['objet'] }}</dt><dd class="font-bold">{{ $d['objets'][$type] }}</dd></div>
            <div><dt class="texte-carte">{{ $d['citoyen'] }}</dt><dd class="font-bold">{{ $objet->user?->name }}</dd></div>
            <div><dt class="texte-carte">{{ $d['telephone'] }}</dt><dd>{{ $objet->user?->telephone }}</dd></div>
            <div><dt class="texte-carte">{{ $d['statut'] }}</dt><dd class="font-bold">{{ $objet->statut->label() }}</dd></div>
            <div><dt class="texte-carte">{{ $d['montant'] }}</dt><dd class="font-bold">{{ format_fcfa($objet->montantAPayer() + $objet->fraisAPayer()) }}</dd></div>
            <div><dt class="texte-carte">{{ $d['echeance'] }}</dt><dd>{{ $objet->expire_le?->format('d/m/Y H:i') }}</dd></div>
        </dl>
    </section>

    <section class="bo-carte" aria-labelledby="paiements-titre">
        <h2 id="paiements-titre" class="!text-[1.15rem]">{{ $d['paiements'] }}</h2>
        @if ($objet->paiements->isEmpty())
            <p class="texte-carte mt-2">{{ $d['aucun_paiement'] }}</p>
        @else
            <div class="mt-3 overflow-x-auto">
                <table class="bo-tableau" data-paiements>
                    <thead><tr>@foreach ($p['colonnes_paiements'] as $libelle)<th scope="col">{{ $libelle }}</th>@endforeach</tr></thead>
                    <tbody>
                        @foreach ($objet->paiements->sortByDesc('id') as $paiement)
                            @php($correction = $paiement->corrections->firstWhere('statut', CorrectionPaiement::PROPOSEE))
                            <tr data-paiement="{{ $paiement->reference }}">
                                <td class="font-mono font-semibold">{{ $paiement->reference }}</td>
                                <td class="whitespace-nowrap">{{ $paiement->created_at->format('d/m/Y H:i') }}</td>
                                <td>{{ $paiement->canal?->libelle }}<span class="texte-carte block">{{ $paiement->estManuel() ? $d['manuel'] : $d['operateur'] }}</span></td>
                                <td class="whitespace-nowrap">{{ format_fcfa($paiement->montant + $paiement->frais) }}</td>
                                <td>{{ $paiement->statut->label() }}</td>
                                <td>
                                    @if ($paiement->controle)
                                        <span class="font-semibold">{{ $p['controles'][$paiement->controle] }}</span>
                                        <span class="texte-carte block">{{ $paiement->controleur?->name }} · {{ $paiement->controle_le?->format('d/m/Y H:i') }}</span>
                                        @if ($paiement->controle_reference)<span class="texte-carte block">{{ $paiement->controle_reference }}</span>@endif
                                        @if ($paiement->controle_motif)<span class="texte-carte block">{{ $paiement->controle_motif }}</span>@endif
                                    @endif
                                    @if ($correction)<span class="texte-carte block" data-correction-en-attente>{{ $d['correction_en_attente'] }}</span>@endif
                                </td>
                                <td>
                                    <div class="flex justify-end gap-2">
                                        @if ($rapprocher && $paiement->estManuel() && $paiement->statut === StatutPaiement::EnAttente)
                                            <x-admin.fenetre :id="'controle-'.$paiement->id" :titre="__('admin.paiements.actions.controler_titre', ['reference' => $paiement->reference])"
                                                             :action="route('admin.paiements.controle', $paiement)" :bouton="$p['actions']['enregistrer']" declencheur-taille="petit">
                                                <x-slot:declencheur>{{ $p['actions']['controler'] }}</x-slot:declencheur>
                                                <x-admin.champ nom="controle" :libelle="ucfirst($p['champs']['controle'])" type="radio" :options="$p['controles']" :valeur="Paiement::RECU" obligatoire />
                                                <x-admin.champ nom="controle_reference" :libelle="ucfirst($p['champs']['controle_reference'])" maxlength="100" class="mt-4" :aide="$p['aides']['controle_reference']" />
                                                <x-admin.champ nom="motif" :libelle="ucfirst($p['champs']['motif'])" type="zone" maxlength="1000" class="mt-4" :aide="$p['aides']['motif']" />
                                            </x-admin.fenetre>
                                        @elseif ($rapprocher && $paiement->statut === StatutPaiement::Confirme && ! $correction)
                                            <x-admin.fenetre :id="'correction-'.$paiement->id" :titre="__('admin.paiements.actions.corriger_titre', ['reference' => $paiement->reference])"
                                                             :action="route('admin.paiements.correction', $paiement)" :bouton="$p['actions']['proposer']" declencheur-variante="contour" declencheur-taille="petit">
                                                <x-slot:declencheur>{{ $p['actions']['corriger'] }}</x-slot:declencheur>
                                                <p class="texte-carte mb-4">{{ $p['aides']['correction'] }}</p>
                                                <x-admin.champ nom="motif" :libelle="ucfirst($p['champs']['motif'])" type="zone" maxlength="1000" obligatoire />
                                            </x-admin.fenetre>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="bo-carte" aria-labelledby="relances-titre">
        <h2 id="relances-titre" class="!text-[1.15rem]">{{ $d['relances'] }}</h2>
        @if ($relances->isEmpty())
            <p class="texte-carte mt-2">{{ $d['aucune_relance'] }}</p>
        @else
            <div class="mt-3 overflow-x-auto">
                <table class="bo-tableau" data-relances>
                    <thead><tr>@foreach ($p['colonnes_relances'] as $libelle)<th scope="col">{{ $libelle }}</th>@endforeach</tr></thead>
                    <tbody>
                        @foreach ($relances as $relance)
                            <tr>
                                <td class="whitespace-nowrap">{{ $relance->created_at->format('d/m/Y H:i') }}</td>
                                <td>{{ $p['types_relance'][$relance->type] }}@if ($relance->message)<span class="texte-carte block">{{ $relance->message }}</span>@endif</td>
                                <td>{{ collect($relance->canaux)->map(fn ($c) => $p['canaux'][$c] ?? $c)->implode(', ') }}</td>
                                <td>{{ $relance->auteur?->name ?? $d['automatique'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    @if ($remboursements->isNotEmpty())
        <section class="bo-carte" aria-labelledby="remboursements-titre">
            <h2 id="remboursements-titre" class="!text-[1.15rem]">{{ $d['remboursements'] }}</h2>
            <ul class="mt-2 space-y-1">
                @foreach ($remboursements as $r)
                    <li><span class="font-mono font-semibold">{{ $r->reference }}</span> · {{ format_fcfa($r->montant) }} · {{ $r->libelleStatut() }}</li>
                @endforeach
            </ul>
        </section>
    @endif
</x-admin.layout>
