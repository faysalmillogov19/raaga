<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Dossier d'identité ancien qui ne suit plus les règles des pièces : à mettre à jour (notification et e-mail). */
class PiecesAMettreAJour extends Notification
{
    use Queueable;

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $notifiable->email ? ['database', 'mail'] : ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['message' => __('public.verification.mise_a_jour.message'), 'url' => route('espace.verification'), 'type' => 'pieces_a_mettre_a_jour'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('public.verification.mise_a_jour.sujet'))
            ->greeting(__('admin.utilisateurs.mail.bonjour', ['nom' => $notifiable->name]))
            ->line(__('public.verification.mise_a_jour.message'))
            ->action(__('public.verification.mise_a_jour.bouton'), route('espace.verification'));
    }
}
