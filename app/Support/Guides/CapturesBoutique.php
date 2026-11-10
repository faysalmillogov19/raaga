<?php

namespace App\Support\Guides;

use App\Enums\StatutBoutique;
use App\Models\Boutique;
use App\Models\CampagneBoutiques;
use App\Models\InscriptionBoutique;
use App\Models\ReservationBoutique;
use App\Models\User;
use App\Services\Boutiques\ReservationsBoutiques;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Captures du guide « Obtenir une boutique » (10/11/2026), sur le poste de développement : une citoyenne fictive crée
 * son compte, répond « Non, pas encore » à la formalisation, préréserve, paie (simulateur masqué sur les captures),
 * dépose ensuite son IFU et son RCCM, puis reçoit sa carte. Les décisions du back-office (identité conforme,
 * attribution) passent par les écrans des comptes de démonstration, sans capture.
 */
final class CapturesBoutique
{
    public function __construct(private readonly Pilote $p) {}

    /** @param  callable(string): void  $suivi  une ligne par étape franchie */
    public function executer(callable $suivi): void
    {
        $pieces = $this->pieces();
        $campagne = CampagneBoutiques::query()->latest('id')->first() ?? throw new RuntimeException('Guide : aucune campagne de boutiques sur ce poste.');
        $prises = InscriptionBoutique::query()->pluck('boutique_id')->all();
        $boutique = Boutique::query()->where('statut', StatutBoutique::Disponible)->whereIn('site_id', (array) $campagne->sites)
            ->whereIn('type_boutique_id', (array) $campagne->types)->whereNotIn('id', $prises)->whereHas('type', fn ($q) => $q->proposables())
            ->orderBy('code')->get()->first(fn (Boutique $b) => ReservationsBoutiques::tarifer($b) !== null)
            ?? throw new RuntimeException('Guide : aucune boutique libre et tarifée dans la campagne.');
        $graine = str_pad((string) (time() % 1000000), 6, '0', STR_PAD_LEFT);
        $local = '69'.$graine;
        $p = $this->p;

        // Compte.
        $p->aller('/inscription');
        $p->capture('compte-telephone', 'main');
        $p->saisir('input[name=telephone]', $local);
        $p->envoyer('form button[type=submit]');
        $p->capture('compte-code', 'main');
        $p->code();
        $p->cliquer('input[name=type_titulaire][value=PHYSIQUE]');
        $p->saisir('#nom', 'COMPAORÉ');
        $p->saisir('#prenoms', 'Mariam');
        $p->saisir('#date_naissance', '1991-04-12');
        $p->cliquer('#commune_id-saisie');
        $p->saisir('#commune_id-saisie', 'bobo');
        usleep(400000);
        $p->capture('compte-identite', 'main', 1700);
        $p->js('document.querySelector(".choix-commune-option").dispatchEvent(new MouseEvent("mousedown", {bubbles: true}))');
        $p->cliquer('#conditions');
        $p->envoyer('form button.btn-plein');
        $suivi('compte créé');

        // Recherche selon le budget, sur le site de la boutique.
        $p->aller('/boutiques?site='.$boutique->site_id.'&budget_max=8000000');
        $p->js('(() => { const h = [...document.querySelectorAll("h2, h3")].find(x => x.innerText.trim() === "Rechercher une boutique"); const c = h?.closest(".carte, section") ?? h?.parentElement; if (c) c.id = "guide-recherche"; })()');
        $p->capture('recherche', '#guide-recherche', 1400);

        // Identité d'abord, puis dépôt de la demande.
        $p->aller('/boutiques/plan/'.$boutique->code);
        $p->capture('identite-avant', 'main');
        $p->envoyer('[data-lien-verification]');
        $p->cliquer('input[name=type_piece][value=CNIB]');
        $p->fichier('#recto', $pieces['recto']);
        $p->fichier('#verso', $pieces['verso']);
        $p->fichier('#selfie', $pieces['selfie']);
        $p->saisir('#numero_piece', '0304060261'.str_pad((string) (time() % 10000000), 7, '0', STR_PAD_LEFT));
        $p->saisir('#piece_expire_le', now()->addYears(6)->toDateString());
        $p->cliquer('input[name=sexe][value=F]');
        $p->saisir('#lieu_naissance', 'Bobo-Dioulasso');
        $p->capture('verification-pieces', '[data-depot-kyc]', 1500);
        $p->cliquer('[data-formalisee=oui]');
        $p->capture('formalisation-oui', '[data-formalisation]', 1500);
        $p->cliquer('[data-formalisee=non]');
        $p->capture('formalisation-non', '[data-formalisation]');
        $p->envoyer('[data-depot-kyc] button.btn-primaire');
        $p->fermerMessage();
        $suivi('demande de vérification déposée');

        // Préréservation.
        $p->capture('prereservation-formulaire', '[data-formulaire-reservation]', 1600);
        $p->js('(() => { const s = document.querySelector("select[name=secteur_activite_id]"); if (s) { s.selectedIndex = 1; s.dispatchEvent(new Event("change", {bubbles: true})); } })()');
        if ($p->js('!!document.querySelector("#personne_prevenir_nom")')) {
            $p->saisir('#personne_prevenir_nom', 'COMPAORÉ Issa');
            $p->saisir('#personne_prevenir_telephone', '70 11 22 33');
        }
        $p->js('document.querySelectorAll("input[name=\'engagements[]\']").forEach(c => { if (!c.checked) c.click(); })');
        $p->envoyer('[data-formulaire-reservation] button[type=submit]');
        $suivi('boutique '.$boutique->code.' préréservée');
        $p->capture('caution-a-payer', 'main', 1500);

        // Caution, puis avance (simulateur de paiement local, masqué sur les captures).
        $reference = explode('/', trim($p->chemin(), '/'))[2] ?? throw new RuntimeException('Guide : référence de réservation introuvable.');
        $this->payer('caution-operateur');
        $p->capture('caution-payee', 'main', 1500);
        $p->aller('/boutiques/reservations/'.$reference.'/avance');
        $this->payer(null);
        $p->capture('avance-payee', 'main', 1500);
        $p->aller('/espace?onglet=boutiques');
        $p->capture('mes-boutiques-recepisse', 'main', 1600);
        $suivi('caution et avance payées');

        // Back-office : identité conforme.
        $citoyen = User::query()->where('telephone', '+226'.$local)->sole();
        $this->conforme($citoyen);
        $suivi('identité validée');

        // Formalisation : IFU, puis RCCM.
        $p->nouvelleSession();
        $p->connecterCitoyen($local);
        $p->aller('/espace/verification');
        $p->saisir('#numero_ifu_formalisation', '69'.$graine.'K');
        $p->fichier('#attestation_ifu', $pieces['ifu']);
        $p->capture('votre-ifu', '[data-ifu-formalisation]');
        $p->envoyer('[data-ifu-formalisation] button[type=submit]');
        $p->fermerMessage();
        $p->saisir('[data-rccm] input[name=rccm]', 'BF BOB 2026 A '.substr($graine, -5));
        $p->saisir('#rccm_immatricule_le', now()->subMonth()->toDateString());
        $p->js('(() => { const s = document.querySelector("#rccm_greffe_id"); s.value = s.options[2]?.value ?? s.options[1].value; s.dispatchEvent(new Event("change", {bubbles: true})); })()');
        $p->fichier('#document_rccm', $pieces['rccm']);
        $p->capture('votre-rccm', '[data-rccm]');
        $p->envoyer('[data-rccm] button[type=submit]');
        $p->fermerMessage();
        $suivi('IFU et RCCM déposés');

        // Back-office : justificatifs vérifiés, attribution ; fichiers de la carte générés.
        $this->conforme($citoyen->fresh());
        $this->attribuer(ReservationBoutique::query()->where('reference', $reference)->sole());
        Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--tries' => 3, '--timeout' => 180]);
        $suivi('boutique attribuée, carte générée');

        $p->nouvelleSession();
        $p->connecterCitoyen($local);
        $p->aller('/espace?onglet=boutiques');
        $p->capture('boutique-attribuee', 'main', 1300);
        $p->aller('/espace/carte/commercant');
        $p->capture('carte', 'main', 1300);
        $p->fermer();
        File::deleteDirectory(dirname($pieces['recto']));
    }

    /** Paiement par Orange Money, confirmé par le simulateur ; capture de l'attente de confirmation si demandée. */
    private function payer(?string $capture): void
    {
        $this->p->js('[...document.querySelectorAll("label")].find(l => l.innerText.includes("Orange Money"))?.click()');
        usleep(300000);
        $this->p->envoyerTexte('Payer', 'button');
        if ($capture !== null) {
            $this->p->capture($capture, 'main', 1500);
        }
        $this->p->envoyerTexte('Simuler un paiement réussi', 'button');
        $this->p->fermerMessage();
    }

    /** ADMIN de démonstration : dossier marqué conforme (date d'expiration reprise du dossier). */
    private function conforme(User $citoyen): void
    {
        $this->p->nouvelleSession();
        $this->p->connecterBackoffice('admin@raaga.test');
        $this->p->aller('/admin/kyc/dossiers/'.$citoyen->id);
        $this->p->js('[...document.querySelectorAll("button")].find(b => b.offsetParent !== null && b.innerText.trim() === "Conforme")?.click()');
        usleep(600000);
        $this->p->js('(() => { const b = [...document.querySelectorAll("dialog[open] button")].find(b => b.innerText.trim() === "Marquer conforme"); if (b) b.id = "guide-conforme"; })()');
        $this->p->envoyer('#guide-conforme');
        if ($citoyen->fresh()->statut_kyc?->value !== 'VALIDE') {
            throw new RuntimeException('Guide : le dossier d’identité n’a pas pu être marqué conforme.');
        }
    }

    /** VALIDATEUR de démonstration : attribution avec la date du jour comme date de contrat. */
    private function attribuer(ReservationBoutique $reservation): void
    {
        $this->p->nouvelleSession();
        $this->p->connecterBackoffice('validation@raaga.test');
        $this->p->aller('/admin/boutiques/'.$reservation->campagne_boutiques_id.'?onglet=reservations');
        $this->p->js('(() => { const d = document.getElementById("attribuer-'.$reservation->id.'"); d?.closest("[x-data]")?.querySelector("button")?.click(); })()');
        usleep(600000);
        $this->p->saisir('dialog[open] input[name=contrat_signe_le]', now()->toDateString());
        $this->p->js('(() => { const b = [...document.querySelectorAll("dialog[open] button")].find(b => b.innerText.trim() === "Attribuer"); if (b) b.id = "guide-attribuer"; })()');
        $this->p->envoyer('#guide-attribuer');
        if ($reservation->fresh()->statut->value !== 'ATTRIBUEE') {
            throw new RuntimeException('Guide : la boutique n’a pas pu être attribuée.');
        }
    }

    /** Pièces de démonstration marquées « SPÉCIMEN » (images simples). @return array<string, string> */
    private function pieces(): array
    {
        $dossier = storage_path('framework/guides-pieces');
        File::ensureDirectoryExists($dossier);
        $pieces = [];
        foreach (['recto' => 'CNIB - RECTO', 'verso' => 'CNIB - VERSO', 'selfie' => 'PHOTO DU TITULAIRE', 'ifu' => 'ATTESTATION IFU', 'rccm' => 'EXTRAIT RCCM'] as $cle => $texte) {
            $image = imagecreatetruecolor(860, 540);
            imagefill($image, 0, 0, imagecolorallocate($image, 236, 244, 238));
            $encre = imagecolorallocate($image, 30, 40, 35);
            imagerectangle($image, 10, 10, 849, 529, $encre);
            imagestring($image, 5, 330, 250, $texte, $encre);
            imagestring($image, 5, 380, 280, 'SPECIMEN', $encre);
            imagejpeg($image, $pieces[$cle] = $dossier.'/'.$cle.'.jpg', 85);
        }

        return $pieces;
    }
}
