<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Annulation pour identité douteuse, confirmée par le VALIDATEUR : notification dans « Mon espace » et e-mail si le
 * compte a une adresse. Rappelle les souscriptions et candidatures annulées, et le remboursement éventuel.
 */
class AnnulationIdentite extends Notification
{
    /** @param  array{souscriptions: list<string>, candidatures: list<string>, boutiques: int, remboursements: int}  $bilan */
    public function __construct(private readonly array $bilan) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $notifiable->email ? ['database', 'mail'] : ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['message' => $this->message(), 'url' => route('espace'), 'type' => 'annulation_identite'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__('public.annulation_identite.sujet'))
            ->greeting(__('admin.utilisateurs.mail.bonjour', ['nom' => $notifiable->name]))
            ->line($this->message());
        if ($this->bilan['remboursements'] > 0) {
            $mail->line(__('public.annulation_identite.remboursement'));
        }

        return $mail->action(__('public.annulation_identite.bouton'), route('espace'))
            ->salutation(__('admin.utilisateurs.mail.signature', ['plateforme' => plateforme('identite.nomPlateforme')]));
    }

    private function message(): string
    {
        $references = [...$this->bilan['souscriptions'], ...$this->bilan['candidatures']];

        return $references === []
            ? __('public.annulation_identite.sans_dossier')
            : __('public.annulation_identite.message', ['references' => implode(', ', $references)]);
    }
}
