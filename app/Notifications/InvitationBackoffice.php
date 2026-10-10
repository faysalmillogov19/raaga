<?php

namespace App\Notifications;

use App\Enums\Espace;
use App\Support\Telephone;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Lien personnel d'accès à un espace sécurisé (back-office ou espace institutionnel) (invitation ou nouvel accès), valable 48 h, à usage unique.
 */
class InvitationBackoffice extends Notification
{
    public function __construct(
        private readonly string $lien,
        private readonly int $heures,
        private readonly string $role,
        private readonly Espace $espace = Espace::Backoffice,
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
        // Nom de la plateforme et contact d'assistance : paramètres de la plateforme.
        $plateforme = plateforme('identite.nomPlateforme');
        $cle = $this->espace === Espace::Backoffice ? 'admin.utilisateurs.mail' : 'institutionnel.mail';
        $contact = collect([
            plateforme('contacts.numeroVert') ? Telephone::formaterLocal(plateforme('contacts.numeroVert')) : null,
            plateforme('contacts.emailAssistance'),
        ])->filter()->implode(' · ');

        $message = (new MailMessage)
            ->subject(__($cle.'.sujet', ['plateforme' => $plateforme]))
            ->greeting(__('admin.utilisateurs.mail.bonjour', ['nom' => $notifiable->name]))
            ->line(__($cle.'.intro', ['plateforme' => $plateforme, 'role' => $this->role]))
            ->action(__('admin.utilisateurs.mail.bouton'), $this->lien)
            ->line(__('admin.utilisateurs.mail.validite', ['heures' => $this->heures]))
            ->line(__('admin.utilisateurs.mail.securite'))
            ->salutation(__('admin.utilisateurs.mail.signature', ['plateforme' => $plateforme]));

        return $contact ? $message->line(__('admin.utilisateurs.mail.assistance', ['contact' => $contact])) : $message;
    }
}
