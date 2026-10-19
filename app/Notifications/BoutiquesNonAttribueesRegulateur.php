<?php

namespace App\Notifications;

use App\Models\CampagneBoutiques;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Fin d'une relance de la liste d'attente générale : des boutiques restent libres et la campagne ne prend plus de
 * réservations. Le RÉGULATEUR est invité à ouvrir une nouvelle campagne. Back-office et e-mail.
 */
class BoutiquesNonAttribueesRegulateur extends Notification
{
    /** @param  list<string>  $codes  boutiques restées libres */
    public function __construct(private readonly CampagneBoutiques $campagne, private readonly array $codes) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $notifiable->email ? ['database', 'mail'] : ['database'];
    }

    /** @return array{message: string, url: string} */
    public function toArray(object $notifiable): array
    {
        return ['message' => $this->message(), 'url' => $this->url()];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('admin.boutiques.relances.notification.sujet', ['campagne' => $this->campagne->nom]))
            ->greeting(__('admin.utilisateurs.mail.bonjour', ['nom' => $notifiable->name]))
            ->line($this->message())
            ->action(__('admin.boutiques.relances.notification.bouton'), $this->url())
            ->salutation(__('admin.utilisateurs.mail.signature', ['plateforme' => plateforme('identite.nomPlateforme')]));
    }

    private function message(): string
    {
        return trans_choice('admin.boutiques.relances.notification.message', count($this->codes), [
            'n' => count($this->codes), 'campagne' => $this->campagne->nom, 'codes' => implode(', ', $this->codes),
        ]);
    }

    private function url(): string
    {
        return route('admin.boutiques.show', $this->campagne);
    }
}
