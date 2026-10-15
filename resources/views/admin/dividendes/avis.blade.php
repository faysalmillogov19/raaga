@php
    use App\Enums\ModeCalculDividende;
    use App\Enums\PayeurFrais;
    use App\Support\AffichageParametres as A;

    $d = $distribution;
    $titre = __('admin.dividendes.actions.avis');
@endphp

<x-admin.layout :titre="$titre" :fil="[['libelle' => __('admin.dividendes.titre'), 'url' => route('admin.dividendes')], ['libelle' => __('admin.dividendes.exercice', ['annee' => $d->exercice]), 'url' => route('admin.dividendes.show', $d)], ['libelle' => $titre]]">
    <article class="bo-carte max-w-[820px] space-y-4 leading-relaxed">
        @include('documents._entete')
        <header>
            <p class="surtitre">Actions B</p>
            <h2>Avis de distribution de dividendes, exercice {{ $d->exercice }}</h2>
            <p class="texte-carte">{{ $d->version ? 'Version '.$d->version : 'Brouillon non soumis' }} · {{ $d->statut->label() }}</p>
        </header>

        <p>L’assemblée générale réunie le {{ A::valeur('ag_date', $d->ag_date) }} (procès-verbal n° {{ $d->ag_pv_numero }}) a décidé de distribuer
            {{ $d->mode_calcul === ModeCalculDividende::MontantGlobal
                ? 'un montant global de '.format_fcfa($d->montant_global).' aux actions B, réparti à parts égales entre les actions'
                : 'un dividende brut de '.format_fcfa($d->dividende_brut_par_action).' par action B' }},
            sur un résultat distribuable de {{ format_fcfa($d->resultat_distribuable) }}.</p>

        <p>Sont bénéficiaires les actionnaires inscrits au registre des actions B le {{ A::valeur('date_arrete', $d->date_arrete) }}. Le paiement interviendra à partir du {{ A::valeur('date_mise_en_paiement', $d->date_mise_en_paiement) }}.</p>

        <p>Une retenue fiscale (IRVM) de {{ A::valeur('taux_irvm_pb', $d->taux_irvm_pb) }} est appliquée sur le montant brut.
            Les frais de versement sont supportés par {{ $d->frais_supportes_par === PayeurFrais::Raaga ? 'Raaga' : 'l’actionnaire' }}.
            Le dividende est versé par {{ A::valeur('canaux_autorises', $d->canaux_autorises) }} ; à défaut de choix, par {{ A::valeur('canal_defaut', $d->canal_defaut) }}.</p>

        <p>Un dividende net inférieur à {{ format_fcfa($d->seuil_minimal) }} est reporté et cumulé au versement suivant. Le dividende des actions bloquées (succession en cours, litige) est consigné jusqu’au déblocage. Un dividende non réclamé est conservé {{ $d->conservation_non_reclames_mois }} mois, puis : {{ A::valeur('traitement_non_reclames', $d->traitement_non_reclames) }}.</p>

        <p class="encadre encadre-attention">Aucun montant de dividende n’est garanti pour les exercices suivants : il dépend des bénéfices réellement distribuables de Raaga et des décisions de l’assemblée générale.</p>
    </article>
</x-admin.layout>
