<?php

namespace App\Notifications;

use App\Models\RelanceInteret;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Liste d'attente générale : des boutiques sont de nouveau libres ; le citoyen, attributaire d'aucune boutique de la
 * campagne, peut manifester à nouveau son intérêt jusqu'à la fin du délai de relance. Notification dans « Mon espace »
 * et e-mail si le compte a une adresse (le SMS part en plus, depuis RelancesInteret).
 */
class RelanceInteretCitoyen extends Notification
{
    /** @param  list<string>  $codes  boutiques relancées */
    public function __construct(private readonly RelanceInteret $relance, private readonly array $codes) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $notifiable->email ? ['database', 'mail'] : ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['message' => $this->message(), 'url' => $this->url(), 'type' => 'relance_interet', 'relance' => $this->relance->id];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('public.boutiques.relance.notifications.sujet'))
            ->greeting(__('admin.utilisateurs.mail.bonjour', ['nom' => $notifiable->name]))
            ->line($this->message())
            ->action(__('public.boutiques.relance.bouton'), $this->url())
            ->salutation(__('admin.utilisateurs.mail.signature', ['plateforme' => plateforme('identite.nomPlateforme')]));
    }

    public function message(): string
    {
        return __('public.boutiques.relance.notifications.message', [
            'codes' => implode(', ', $this->codes), 'date' => $this->relance->fin_le->translatedFormat('j F Y à H\hi'),
        ]);
    }

    private function url(): string
    {
        return route('boutiques');
    }
}
