<?php

namespace App\Notifications;

use App\Models\Carte;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Carte imprimée au guichet (après contrôle de la pièce d'identité) : le titulaire est prévenu, e-mail s'il existe. */
class CarteImprimee extends Notification
{
    use Queueable;

    public function __construct(private readonly Carte $carte) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $notifiable->email ? ['database', 'mail'] : ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['message' => $this->message(), 'url' => CarteEmise::url($this->carte), 'type' => 'carte_imprimee', 'carte' => $this->carte->numero];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('public.cartes.notification.imprimee_sujet'))
            ->greeting(__('admin.utilisateurs.mail.bonjour', ['nom' => $notifiable->name]))
            ->line($this->message())
            ->action(__('public.cartes.notification.voir'), CarteEmise::url($this->carte));
    }

    private function message(): string
    {
        return __('public.cartes.notification.imprimee_message', ['numero' => $this->carte->numero, 'date' => now()->format('d/m/Y à H:i')]);
    }
}
