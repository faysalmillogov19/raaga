<?php

namespace App\Notifications;

use App\Models\CandidatureBoutique;
use App\Models\Paiement;
use App\Models\Souscription;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Décision du CONTRÔLEUR DES PAIEMENTS sur un paiement manuel (reçu, incomplet ou rejeté), envoyée au citoyen dans
 * tous les cas : notification dans « Mon espace » et e-mail si le compte a une adresse.
 */
class ControlePaiementCitoyen extends Notification
{
    public function __construct(
        private readonly Paiement $paiement,
        private readonly Souscription|CandidatureBoutique $objet,
        private readonly bool $libere,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $notifiable->email ? ['database', 'mail'] : ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['message' => $this->message(), 'url' => $this->url(), 'type' => 'controle_'.strtolower((string) $this->paiement->controle),
            'reference' => $this->objet->reference];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__('public.paiement.manuel.notification.sujet', ['reference' => $this->objet->reference]))
            ->greeting(__('admin.utilisateurs.mail.bonjour', ['nom' => $notifiable->name]))
            ->line($this->message());
        if ($this->paiement->controle_motif) {
            $mail->line(__('public.paiement.manuel.notification.motif', ['motif' => $this->paiement->controle_motif]));
        }

        return $mail->action(__('public.relances.bouton'), $this->url())
            ->salutation(__('admin.utilisateurs.mail.signature', ['plateforme' => plateforme('identite.nomPlateforme')]));
    }

    private function message(): string
    {
        $cle = $this->libere ? 'libere' : strtolower((string) $this->paiement->controle);

        return __('public.paiement.manuel.notification.'.$cle, [
            'reference' => $this->objet->reference, 'versement' => $this->paiement->reference_versement,
            'echeance' => $this->objet->expire_le?->translatedFormat('j F Y à H\hi') ?? '',
        ]);
    }

    private function url(): string
    {
        return $this->objet instanceof Souscription
            ? route('paiement.show', $this->objet->reference)
            : route('boutiques.caution.show', $this->objet->reference);
    }
}
