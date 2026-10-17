<?php

namespace App\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Pièce d'identité qui expire dans 30 jours : la carte Raaga ne sera plus valable au-delà (notification et e-mail). */
class PieceBientotExpiree extends Notification
{
    use Queueable;

    public function __construct(private readonly CarbonInterface $expireLe) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $notifiable->email ? ['database', 'mail'] : ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['message' => $this->message(), 'url' => route('espace.verification'), 'type' => 'piece_bientot_expiree'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('public.cartes.notification.piece_sujet'))
            ->greeting(__('admin.utilisateurs.mail.bonjour', ['nom' => $notifiable->name]))
            ->line($this->message())
            ->action(__('public.cartes.notification.voir'), route('espace.verification'));
    }

    private function message(): string
    {
        return __('public.cartes.notification.piece_message', ['date' => $this->expireLe->format('d/m/Y')]);
    }
}
