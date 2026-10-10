@php
    use App\Enums\MethodeAllocation;
    use App\Enums\PayeurFrais;
    use App\Enums\RegleArret;
    use App\Enums\RegleSiEchec;
    use App\Enums\TypeFraisSouscription;
    use App\Support\AffichageParametres as A;

    $t = $tranche;
    $methode = match ($t->methode_allocation) {
        MethodeAllocation::PremierArrive => 'Les demandes sont servies dans l’ordre de leur paiement, jusqu’à épuisement des actions. La dernière demande servie peut l’être partiellement.',
        MethodeAllocation::Prorata => 'Chaque souscripteur reçoit une part des actions proportionnelle à sa demande. Les actions restantes après arrondi sont attribuées une par une aux plus forts restes, puis au paiement le plus ancien.',
        MethodeAllocation::PrioritePetits => 'Chaque souscripteur reçoit d’abord jusqu’à '.format_nombre((int) $t->seuil_garanti_petits).' actions. Les actions restantes sont ensuite réparties au prorata des demandes supplémentaires.',
    };
@endphp

<x-admin.layout :titre="__('admin.tranches.actions.reglement')" :fil="[['libelle' => __('admin.tranches.titre'), 'url' => route('admin.tranches')], ['libelle' => $t->nom, 'url' => route('admin.tranches.show', $t)], ['libelle' => __('admin.tranches.actions.reglement')]]">
    <article class="bo-carte max-w-[820px] space-y-4 leading-relaxed">
        @include('documents._entete')
        <header>
            <p class="surtitre">Souscription populaire</p>
            <h2>Règlement de la souscription — {{ $t->nom }}</h2>
            <p class="texte-carte">{{ $t->version ? 'Version '.$t->version : 'Brouillon non soumis' }} · {{ $t->statut->label() }}</p>
        </header>

        <section><h3>Article 1 — Objet</h3>
            <p>{{ plateforme('mentions.denominationSociale') ?: plateforme('identite.nomPlateforme') }} propose aux citoyens burkinabè ({{ A::valeur('publics_autorises', $t->publics_autorises) }}) de souscrire des actions B, pour un objectif de collecte de {{ format_fcfa($t->objectif_montant) }}.</p></section>

        <section><h3>Article 2 — Prix et quantités</h3>
            <p>Chaque action est proposée à {{ format_fcfa($t->valeur_unitaire) }}. Une souscription porte sur au moins {{ format_nombre($t->min_actions_par_souscription) }} action(s).
                {{ $t->plafond_actions_par_personne ? 'Une même personne ne peut pas détenir plus de '.format_nombre($t->plafond_actions_par_personne).' actions de cette tranche.' : 'Il n’y a pas de plafond par personne.' }}
                {{ $t->souscriptions_multiples ? 'Plusieurs souscriptions d’une même personne s’additionnent.' : 'Une seule souscription par personne est acceptée.' }}</p></section>

        <section><h3>Article 3 — Période</h3>
            <p>La souscription est ouverte du {{ A::valeur('date_ouverture', $t->date_ouverture) }} au {{ A::valeur('date_cloture', $t->date_cloture) }} (heure de Ouagadougou). Une souscription non payée dans les {{ $t->delai_paiement_heures }} heures expire automatiquement.</p></section>

        <section><h3>Article 4 — Si la demande dépasse l’offre</h3>
            <p>{{ $t->regle_arret === RegleArret::DepassementAutorise ? 'L’objectif peut être dépassé de '.$t->pourcentage_depassement_max.' % au plus.' : 'La collecte s’arrête à l’objectif.' }}
                {{ $methode }}
                {{ $t->plafond_concentration_pct ? 'Aucune personne ne peut recevoir plus de '.$t->plafond_concentration_pct.' % des actions de la tranche.' : '' }}
                Les sommes payées pour des actions non attribuées sont remboursées dans un délai de {{ $t->delai_remboursement_jours }} jours.</p></section>

        <section><h3>Article 5 — Si l’objectif n’est pas atteint</h3>
            <p>{{ $t->seuil_reussite_montant ? 'La tranche est réputée réussie si la collecte atteint '.format_fcfa($t->seuil_reussite_montant).'.' : 'Aucun seuil de réussite n’est fixé.' }}
                {{ match ($t->regle_si_echec) {
                    RegleSiEchec::RemboursementIntegral => 'À défaut, toutes les sommes sont intégralement remboursées.',
                    RegleSiEchec::FondsConserves => 'À défaut, les fonds collectés sont conservés et les actions attribuées.',
                    RegleSiEchec::Prolongation => 'À défaut, la souscription est prolongée de '.$t->jours_prolongation.' jours.',
                } }}</p></section>

        <section><h3>Article 6 — Frais</h3>
            <p>{{ match ($t->frais_souscription_type) {
                    TypeFraisSouscription::Aucun => 'Aucun frais de souscription n’est prélevé.',
                    TypeFraisSouscription::Pourcentage => 'Des frais de souscription de '.format_nombre($t->frais_souscription_valeur / 100, 2).' % s’ajoutent au montant.',
                    TypeFraisSouscription::Fixe => 'Des frais de souscription de '.format_fcfa($t->frais_souscription_valeur).' s’ajoutent au montant.',
                } }}
                Les frais de paiement sont supportés par {{ $t->frais_paiement_supportes_par === PayeurFrais::Raaga ? 'Raaga' : 'le souscripteur' }}.
                Moyens de paiement acceptés : {{ A::valeur('canaux_actifs', $t->canaux_actifs) }}.</p></section>

        <section><h3>Article 7 — Vérifications</h3>
            <p>Une pièce d’identité vérifiée est exigée avant tout paiement.
                {{ $t->seuil_kyc_renforce ? 'Au-delà de '.format_fcfa($t->seuil_kyc_renforce).' cumulés, un justificatif d’origine des fonds est demandé.' : '' }}
                {{ $t->seuil_revue_conformite ? 'Au-delà de '.format_fcfa($t->seuil_revue_conformite).' cumulés, la souscription est examinée par la conformité avant confirmation.' : '' }}</p></section>

        <section class="encadre encadre-danger"><h3>Deux règles importantes</h3>
            <p>L’achat d’actions Raaga <strong>ne donne aucun droit ni aucune priorité</strong> pour obtenir une boutique ou un emplacement. Le rendement <strong>n’est pas garanti</strong> : il dépend des bénéfices réellement distribuables de Raaga.</p></section>
    </article>
</x-admin.layout>
