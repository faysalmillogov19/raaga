@php
    $k = __('admin.composants.charte');
    $statuts = [
        $k['statuts_circuit'] => ['Brouillon', 'Soumis', 'Confirmé', 'En révision', 'Rejeté', 'Désactivé', 'Par défaut'],
        $k['statuts_physique'] => ['En construction', 'Livrée', 'En travaux', 'Fermée'],
        $k['statuts_commercial'] => ['Disponible', 'Réservée', 'Attribuée', 'Occupée', 'Suspendue'],
        $k['statuts_imports'] => ['Aperçu', 'Confirmé', 'Refusé', 'Annulé'],
    ];
    // Erreur de démonstration, pour montrer l'état « en erreur » d'un champ.
    $demo = new \Illuminate\Support\ViewErrorBag;
    $demo->put('default', new \Illuminate\Support\MessageBag(['surface' => 'La surface doit être supérieure à 0.']));
    $erreursPage = $errors;
@endphp

<x-admin.layout :titre="$k['titre']" :sous-titre="$k['sous_titre']" :fil="[['libelle' => $k['titre']]]">
    {{-- Boutons --}}
    <section class="bo-carte" aria-labelledby="charte-boutons">
        <h2 id="charte-boutons" class="!text-[1.05rem]">{{ $k['boutons'] }}</h2>

        <p class="adm-intertitre">{{ $k['variantes'] }}</p>
        <div class="flex flex-wrap items-center gap-3">
            <x-admin.bouton>Principal</x-admin.bouton>
            <x-admin.bouton variante="secondaire">Secondaire</x-admin.bouton>
            <x-admin.bouton variante="contour">Contour</x-admin.bouton>
            <x-admin.bouton variante="discret">Discret</x-admin.bouton>
            <x-admin.bouton variante="danger">Supprimer</x-admin.bouton>
            <x-admin.bouton disabled>Désactivé</x-admin.bouton>
        </div>

        <p class="adm-intertitre">{{ $k['tailles'] }}</p>
        <div class="flex flex-wrap items-center gap-3">
            <x-admin.bouton taille="petit">Petit · tableaux</x-admin.bouton>
            <x-admin.bouton>Normal</x-admin.bouton>
            <x-admin.bouton taille="grand">Grand · formulaires</x-admin.bouton>
        </div>

        <p class="adm-intertitre">{{ $k['icones'] }}</p>
        <div class="flex flex-wrap items-center gap-3">
            <x-admin.bouton icone="plus">Ajouter</x-admin.bouton>
            <x-admin.bouton variante="secondaire" icone="importer">Importer</x-admin.bouton>
            <x-admin.bouton variante="contour" icone="exporter">Exporter</x-admin.bouton>
            <x-admin.bouton variante="contour" icone="modifier" libelle="Modifier" />
            <x-admin.bouton chargement>Import en cours…</x-admin.bouton>
        </div>

        <p class="adm-intertitre">{{ $k['circuit'] }}</p>
        <div class="flex flex-wrap items-center gap-3">
            <x-admin.circuit form="charte-inerte" />
            <x-admin.confirmation id="charte-soumettre" etape="soumettre" titre="Soumettre au validateur" message="Exemple : rien n’est envoyé." action="#" />
            <x-admin.confirmation id="charte-confirmer" etape="confirmer" titre="Confirmer" message="Exemple : rien n’est envoyé." action="#" />
            <x-admin.confirmation id="charte-rejeter" etape="rejeter" titre="Rejeter" message="Le motif est transmis à l’auteur." action="#" />
            <x-admin.confirmation id="charte-reviser" etape="reviser" titre="Remettre en révision" message="Exemple : rien n’est envoyé." action="#" />
        </div>
    </section>

    {{-- Statuts --}}
    <section class="bo-carte" aria-labelledby="charte-statuts">
        <h2 id="charte-statuts" class="!text-[1.05rem]">{{ $k['statuts'] }}</h2>
        @foreach ($statuts as $groupe => $libelles)
            <p class="adm-intertitre">{{ $groupe }}</p>
            <div class="flex flex-wrap gap-2">
                @foreach ($libelles as $libelle)
                    <x-admin.badge-statut :statut="$libelle" />
                @endforeach
            </div>
        @endforeach
        <p class="texte-carte mt-4">{{ $k['statuts_note'] }}</p>
    </section>

    {{-- Champs --}}
    <section class="bo-carte" aria-labelledby="charte-champs">
        <h2 id="charte-champs" class="!text-[1.05rem]">{{ $k['champs'] }}</h2>
        <form id="charte-inerte" action="#" onsubmit="return false" class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-4" novalidate>
            <x-admin.champ nom="code_site" libelle="Code du site" valeur="RAA" obligatoire aide="3 caractères. Non modifiable après confirmation." />
            @php
                view()->share('errors', $demo);
            @endphp
            <x-admin.champ nom="surface" libelle="Surface (m²)" valeur="-8,6" />
            @php
                view()->share('errors', $erreursPage);
            @endphp
            <x-admin.champ nom="niveau" libelle="Niveau" type="liste" :options="['RC' => 'RC · Rez-de-chaussée', 'R1' => 'R1 · R+1']" valeur="RC" />
            <x-admin.champ nom="code_boutique" libelle="Code de la boutique" type="verrouille" valeur="RAA-R1-A-045" aide="Calculé automatiquement." />
            <x-admin.champ nom="observations" libelle="Observations" type="zone" valeur="Boutique d’angle, donne sur l’allée principale." class="md:col-span-2" />
            <x-admin.champ nom="position" libelle="Position" type="radio" :options="['ANGLE' => 'Angle', 'FACADE' => 'Façade', 'ALLEE' => 'Allée intérieure']" valeur="ANGLE" />
            <div class="grid content-start gap-2">
                <x-admin.champ nom="attribuable" libelle="Attribuable par tirage" type="case" :valeur="true" />
                <x-admin.champ nom="actif" libelle="Type actif" type="interrupteur" :valeur="true" />
            </div>
            <x-admin.depot-fichier nom="fichier_demo" libelle="Fichier Excel" class="md:col-span-2" />
        </form>
    </section>

    {{-- Messages --}}
    <section class="bo-carte" aria-labelledby="charte-messages">
        <h2 id="charte-messages" class="!text-[1.05rem]">{{ $k['messages'] }}</h2>
        <div class="mt-4">
            <x-admin.message type="ok" titre="Import confirmé.">7 boutiques créées, 12 mises à jour, 830 inchangées.</x-admin.message>
            <x-admin.message type="info" titre="Site soumis.">Le validateur a été notifié.</x-admin.message>
            <x-admin.message type="avertissement" titre="Écart de capacité.">849 boutiques importées pour 856 prévues. L’import reste possible.</x-admin.message>
            <x-admin.message type="erreur" titre="Import refusé : 14 erreurs.">Ligne 12 : site RAB inconnu. Vouliez-vous dire RAA ?</x-admin.message>
        </div>
    </section>

    {{-- Onglets et pagination --}}
    <section class="bo-carte" aria-labelledby="charte-onglets">
        <h2 id="charte-onglets" class="!text-[1.05rem]">{{ $k['onglets'] }}</h2>
        <x-admin.onglets class="mt-4" actif="informations" libelle="Exemple d’onglets"
                         :onglets="['informations' => ['Informations', '#'], 'plans' => ['Plans', '#'], 'versions' => ['Versions', '#']]" />
        <x-admin.pagination :page="1" :derniere="183" :url="fn (int $p) => '#page-'.$p" class="mt-4" />
    </section>

    {{-- Fenêtres de confirmation --}}
    <section class="bo-carte" aria-labelledby="charte-confirmations">
        <h2 id="charte-confirmations" class="!text-[1.05rem]">{{ $k['confirmations'] }}</h2>
        <div class="mt-4 flex flex-wrap gap-3">
            <x-admin.confirmation id="charte-supprimer" titre="Supprimer la boutique" message="Son code reste réservé et ne sera jamais réutilisé." action="#" bouton="Supprimer" danger>
                <x-slot:declencheur>Supprimer</x-slot:declencheur>
            </x-admin.confirmation>
            <x-admin.confirmation id="charte-desactiver" titre="Désactiver le type" message="Il disparaît des saisies et des imports ; les boutiques existantes le gardent." action="#" bouton="Désactiver" motif="facultatif">
                <x-slot:declencheur>Désactiver</x-slot:declencheur>
            </x-admin.confirmation>
        </div>
    </section>
</x-admin.layout>
