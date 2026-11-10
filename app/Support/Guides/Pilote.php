<?php

namespace App\Support\Guides;

use HeadlessChromium\Browser;
use HeadlessChromium\BrowserFactory;
use HeadlessChromium\Clip;
use HeadlessChromium\Page;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

/**
 * Pilotage de Chrome pour les captures des guides (10/11/2026) : navigation sur le serveur local lancé par
 * raaga:guides, saisie, envoi de formulaires, captures JPEG. Mode capture : ce qui n'existe qu'en test (simulateur de
 * paiement, lien vers les SMS de recette) est masqué avant chaque capture.
 */
final class Pilote
{
    /** Éléments masqués sur les captures : simulateur de paiement, lien vers la page des SMS de recette. */
    private const MASQUES = '[data-simulateur], a[href*="/recette/sms"], [data-bandeau-recette]';

    private Browser $navigateur;

    private Page $page;

    public function __construct(private readonly string $base, private readonly string $sortie)
    {
        File::ensureDirectoryExists($sortie);
        $this->navigateur = (new BrowserFactory((string) config('raaga.cartes.chrome')))->createBrowser([
            'headless' => true, 'windowSize' => [1280, 860], 'startupTimeout' => (int) config('raaga.cartes.chrome_delai', 30),
            'customFlags' => ['--disable-gpu', '--hide-scrollbars', '--force-color-profile=srgb'],
        ]);
        $this->page = $this->navigateur->createPage();
    }

    /** Nouvelle session (autre profil) : nouvelle fenêtre sans cookies. */
    public function nouvelleSession(): void
    {
        $this->navigateur->close();
        $this->navigateur = (new BrowserFactory((string) config('raaga.cartes.chrome')))->createBrowser([
            'headless' => true, 'windowSize' => [1280, 860], 'customFlags' => ['--disable-gpu', '--hide-scrollbars', '--force-color-profile=srgb'],
        ]);
        $this->page = $this->navigateur->createPage();
    }

    public function fermer(): void
    {
        $this->navigateur->close();
    }

    public function aller(string $chemin): void
    {
        $this->page->navigate(str_starts_with($chemin, 'http') ? $chemin : $this->base.$chemin)->waitForNavigation(Page::NETWORK_IDLE, 30000);
        usleep(300000);
    }

    public function js(string $code): mixed
    {
        return $this->page->evaluate($code)->getReturnValue(15000);
    }

    public function chemin(): string
    {
        return (string) $this->js('location.pathname');
    }

    /** Saisit une valeur (événements input et change, pour Alpine). */
    public function saisir(string $selecteur, string $valeur): void
    {
        $ok = $this->js('(() => { const e = document.querySelector('.json_encode($selecteur).'); if (!e) return false; e.focus(); e.value = '.json_encode($valeur).';'
            .' e.dispatchEvent(new Event("input", {bubbles: true})); e.dispatchEvent(new Event("change", {bubbles: true})); return true; })()');
        if (! $ok) {
            throw new RuntimeException("Guide : champ introuvable « {$selecteur} » sur ".$this->chemin().'.');
        }
    }

    public function cliquer(string $selecteur): void
    {
        if (! $this->js('(() => { const e = document.querySelector('.json_encode($selecteur).'); if (!e) return false; e.click(); return true; })()')) {
            throw new RuntimeException("Guide : élément introuvable « {$selecteur} » sur ".$this->chemin().'.');
        }
        usleep(250000);
    }

    /** Clique et attend la page suivante (30 secondes au plus). */
    public function envoyer(string $selecteur): void
    {
        $avant = $this->js('location.href + "|" + performance.timeOrigin');
        $this->cliquer($selecteur);
        for ($i = 0; $i < 150; $i++) {
            usleep(200000);
            try {
                if ($this->js('location.href + "|" + performance.timeOrigin') !== $avant && $this->js('document.readyState') === 'complete') {
                    break;
                }
            } catch (Throwable) {
            }
        }
        usleep(400000);
    }

    /** Clique le bouton ou le lien visible dont le texte contient $texte, puis attend la page suivante. */
    public function envoyerTexte(string $texte, string $portee = 'button, a'): void
    {
        $id = 'guide-cible-'.mt_rand();
        $ok = $this->js('(() => { const e = [...document.querySelectorAll('.json_encode($portee).')].find(x => x.offsetParent !== null && x.innerText.toLowerCase().includes('
            .json_encode(mb_strtolower($texte)).')); if (!e) return false; e.id = '.json_encode($id).'; return true; })()');
        if (! $ok) {
            throw new RuntimeException("Guide : bouton « {$texte} » introuvable sur ".$this->chemin().'.');
        }
        $this->envoyer('#'.$id);
    }

    public function fichier(string $selecteur, string $chemin): void
    {
        ($this->page->dom()->querySelector($selecteur) ?? throw new RuntimeException("Guide : champ de fichier introuvable « {$selecteur} »."))->sendFile($chemin);
    }

    /** Ferme la fenêtre « Opération réussie », si elle est ouverte. */
    public function fermerMessage(): void
    {
        $this->js('[...document.querySelectorAll("button")].filter(b => b.innerText.trim() === "Compris" && b.offsetParent !== null).forEach(b => b.click())');
        usleep(400000);
    }

    /** Code SMS (journal des SMS simulés) collé dans la première case ; envoi automatique ou par le bouton. */
    public function code(): void
    {
        $lignes = file((string) config('raaga.sms.fichier_recette'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        preg_match('/\b(\d{6})\b/', (string) (json_decode((string) end($lignes), true)['message'] ?? ''), $m);
        $this->saisir('.otp', $m[1] ?? throw new RuntimeException('Guide : aucun code SMS dans le journal des SMS simulés.'));
        usleep(300000);
        if ($this->js('!!document.querySelector(".otp")')) {
            $this->envoyer('form[x-ref=formulaire] button[type=submit]');
        }
    }

    public function connecterCitoyen(string $local): void
    {
        $this->aller('/connexion');
        $this->saisir('input[name=telephone]', $local);
        $this->envoyer('form button[type=submit]');
        $this->code();
    }

    /** Back-office du poste local : comptes de démonstration (mot de passe = adresse), double authentification facultative. */
    public function connecterBackoffice(string $email): void
    {
        $this->aller('/admin/connexion');
        $this->saisir('input[name=email]', $email);
        $this->saisir('input[name=password]', $email);
        $this->envoyer('form button[type=submit]');
    }

    /** Capture JPEG : la fenêtre, ou la zone d'un élément (marge de 16 px), en mode capture. */
    public function capture(string $nom, ?string $selecteur = null, int $hauteurMax = 1600): void
    {
        $this->js('document.querySelectorAll('.json_encode(self::MASQUES).').forEach(e => { e.style.display = "none"; })');
        $fichier = $this->sortie.'/'.$nom.'.jpg';
        $options = ['format' => 'jpeg', 'quality' => 80];
        if ($selecteur !== null) {
            $r = $this->js('(() => { const e = document.querySelector('.json_encode($selecteur).'); if (!e) return null; e.scrollIntoView({block: "start"}); const b = e.getBoundingClientRect();'
                .' return {x: b.left + scrollX, y: b.top + scrollY, w: b.width, h: b.height}; })()')
                ?? throw new RuntimeException("Guide : zone introuvable « {$selecteur} » sur ".$this->chemin().'.');
            usleep(200000);
            $options += ['clip' => new Clip(max(0, $r['x'] - 16), max(0, $r['y'] - 16), min(1280, $r['w'] + 32), min($hauteurMax, $r['h'] + 32)), 'captureBeyondViewport' => true];
        }
        $this->page->screenshot($options)->saveToFile($fichier);
        // Éléments masqués rendus à la page : le parcours peut s'en servir ensuite (simulateur de paiement).
        $this->js('document.querySelectorAll('.json_encode(self::MASQUES).').forEach(e => { e.style.display = ""; })');
    }
}
