<?php

namespace App\Notifications;

use App\Models\AppelFonds;
use App\Services\Documents\GenerateurPdf;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Avis d'appel de fonds envoyé au signataire (J-15, J-3, jour de l'échéance) ou relance après
 * l'échéance, avec l'avis PDF en pièce jointe.
 */
class AppelFondsNotification extends Notification
{
    /**
     * @param  int|null  $jours  jours restants avant l'échéance (null = relance d'un appel en retard)
     */
    public function __construct(
        private readonly AppelFonds $appel,
        private readonly ?int $jours,
    ) {}

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
        $variables = [
            'numero' => $a->numero,
            'convention' => $a->convention->reference,
            'montant' => format_fcfa($a->resteAPayer()),
            'echeance' => $a->echeance->translatedFormat('j F Y'),
            'reference' => $a->reference_virement,
            'jours' => $this->jours,
        ];
        $cle = match (true) {
            $this->jours === null => 'relance',
            $this->jours === 0 => 'jour',
            default => 'rappel',
        };

        return (new MailMessage)
            ->subject(__("institutionnel.mail_appel.sujet_{$cle}", $variables))
            ->greeting(__('admin.utilisateurs.mail.bonjour', ['nom' => $notifiable->name]))
            ->line(__("institutionnel.mail_appel.{$cle}", $variables))
            ->line(__('institutionnel.mail_appel.reference', $variables))
            ->action(__('institutionnel.mail_appel.bouton'), route('institutionnel.appels.show', $a))
            ->attachData(app(GenerateurPdf::class)->rendre('documents.avis-appel-fonds', ['appel' => $a]),
                'avis-appel-de-fonds-'.$a->reference_virement.'.pdf', ['mime' => 'application/pdf'])
            ->salutation(__('admin.utilisateurs.mail.signature', ['plateforme' => plateforme('identite.nomPlateforme')]));
    }
}
