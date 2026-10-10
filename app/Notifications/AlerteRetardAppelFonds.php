<?php

namespace App\Notifications;

use App\Models\AppelFonds;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Alerte au VALIDATEUR : un appel de fonds a dépassé son échéance sans être soldé.
 */
class AlerteRetardAppelFonds extends Notification
{
    public function __construct(private readonly AppelFonds $appel) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $a = $this->appel;

        return (new MailMessage)
            ->subject(__('institutionnel.mail_appel.sujet_alerte', ['institution' => $a->institution->nomCourt()]))
            ->line(__('institutionnel.mail_appel.alerte', [
                'institution' => $a->institution->denomination,
                'numero' => $a->numero,
                'convention' => $a->convention->reference,
                'montant' => format_fcfa($a->resteAPayer()),
                'echeance' => $a->echeance->translatedFormat('j F Y'),
            ]))
            ->action(__('institutionnel.mail_appel.bouton_alerte'), route('admin.appels-fonds.show', $a));
    }
}
