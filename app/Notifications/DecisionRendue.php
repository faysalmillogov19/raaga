<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Décision du VALIDATEUR sur un élément soumis (site, grille, référentiel, campagne…) : notification dans le
 * back-office et e-mail à l'auteur, avec le motif en cas de rejet.
 */
class DecisionRendue extends Notification
{
    /** @param  string  $decision  confirmer | rejeter */
    public function __construct(
        private readonly string $element,
        private readonly string $libelle,
        private readonly string $decision,
        private readonly ?string $motif,
        private readonly string $url,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $notifiable->email ? ['database', 'mail'] : ['database'];
    }

    /** @return array{message: string, url: string, decision: string, motif: ?string} */
    public function toArray(object $notifiable): array
    {
        return ['message' => $this->message(), 'url' => $this->url, 'decision' => $this->decision, 'motif' => $this->motif];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->message())
            ->greeting(__('admin.utilisateurs.mail.bonjour', ['nom' => $notifiable->name]))
            ->line($this->message());
        if ($this->motif) {
            $mail->line(__('admin.a_valider.notification.motif', ['motif' => $this->motif]));
        }

        return $mail->action(__('admin.a_valider.notification.bouton'), $this->url)
            ->salutation(__('admin.utilisateurs.mail.signature', ['plateforme' => plateforme('identite.nomPlateforme')]));
    }

    private function message(): string
    {
        return __('admin.a_valider.notification.'.$this->decision, ['element' => $this->element, 'libelle' => $this->libelle]);
    }
}
