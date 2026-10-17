{{--
    Notifications en popup (<dialog role="alertdialog">), même composant partout (back-office, site public, espace
    institutionnel, connexion) : icône et couleur selon le type, titre clair, message, bouton « Compris ».
    Succès et information se ferment seuls après 4 secondes (ou au clic) ; erreur et alerte restent ouvertes jusqu'à
    « Compris » ou Échap. Erreurs de formulaire : les messages restent à côté des champs, la popup en donne le résumé.
    Plusieurs messages : affichés l'un après l'autre (public/js/app.js).
--}}
@php
    $t = __('public.popups');
    $messages = [];
    $ajouter = function (string $type, ?string $texte, ?string $titre = null) use (&$messages, $t) {
        if (filled($texte)) {
            $messages[] = ['type' => $type, 'titre' => $titre ?? $t['titres'][$type], 'texte' => $texte];
        }
    };
    // Succès : titre précisé par le message (session « succes_titre »), sinon « Opération réussie ».
    $ajouter('succes', session('succes'), session('succes_titre'));
    $ajouter('information', session('statut'));
    $ajouter('information', session('info'));
    $ajouter('alerte', session('avertissement'));
    $ajouter('erreur', session('erreur'));
    // Messages propres à une page publique, autrefois en bandeau.
    if ($numero = session('contact.numero')) {
        $ajouter('succes', __('public.contact.formulaire.envoye', ['numero' => $numero]), __('public.contact.formulaire.envoye_titre'));
    }
    if ($reference = session('souscription.reservee')) {
        $ajouter('information', __('public.souscription.reservee', ['reference' => $reference]));
    }
    // Erreurs de formulaire : résumé (« 3 champs à corriger »).
    $sac = $errors ?? null;
    if ($sac && $sac->any()) {
        $n = count($sac->keys());
        $ajouter('erreur', trans_choice('public.popups.champs_a_corriger', $n, ['n' => $n]), $t['titres']['formulaire']);
    }
    $icones = ['succes' => 'check', 'information' => 'info', 'alerte' => 'danger', 'erreur' => 'erreur'];
@endphp
@foreach ($messages as $i => $m)
    <dialog class="notification notification-{{ $m['type'] }}" role="alertdialog" aria-modal="true"
            aria-labelledby="notification-{{ $i }}-titre" aria-describedby="notification-{{ $i }}-texte"
            data-notification="{{ $m['type'] }}" @if (in_array($m['type'], ['succes', 'information'], true)) data-fermeture-auto="4000" @endif>
        <form method="dialog" class="notification-cadre">
            <span class="notification-icone"><x-icon :name="$icones[$m['type']]" class="h-7 w-7" /></span>
            <div class="notification-corps">
                <h2 id="notification-{{ $i }}-titre" class="notification-titre">{{ $m['titre'] }}</h2>
                <p id="notification-{{ $i }}-texte" class="notification-texte">{{ $m['texte'] }}</p>
            </div>
            <button type="submit" class="notification-bouton" data-notification-fermer>{{ $t['compris'] }}</button>
        </form>
    </dialog>
@endforeach
