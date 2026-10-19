<?php

namespace App\Notifications;

use App\Models\ReservationBoutique;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Liste d'attente d'une boutique : le citoyen en devient l'attributaire (l'attributaire précédent l'a perdue). La
 * boutique est pré-réservée à son nom : caution à payer avant la date limite, sinon elle passe au suivant. Notification
 * dans « Mon espace » et e-mail si le compte a une adresse (le SMS part en plus, depuis ListesAttente).
 */
class ListeAttenteBoutiqueCitoyen extends Notification
{
    public function __construct(private readonly ReservationBoutique $reservation) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $notifiable->email ? ['database', 'mail'] : ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['message' => $this->message(), 'url' => $this->url(), 'type' => 'liste_attente_retenue', 'reference' => $this->reservation->reference];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('public.boutiques.attente.notifications.sujet', ['boutique' => (string) $this->reservation->boutique?->code]))
            ->greeting(__('admin.utilisateurs.mail.bonjour', ['nom' => $notifiable->name]))
            ->line($this->message())
            ->action(__('public.boutiques.reservation.payer'), $this->url())
            ->salutation(__('admin.utilisateurs.mail.signature', ['plateforme' => plateforme('identite.nomPlateforme')]));
    }

    public function message(): string
    {
        return __('public.boutiques.attente.notifications.retenue', [
            'boutique' => (string) $this->reservation->boutique?->code, 'montant' => format_fcfa($this->reservation->caution),
            'date' => $this->reservation->expire_le?->translatedFormat('j F Y à H\hi') ?? '',
        ]);
    }

    private function url(): string
    {
        return route('boutiques.reservation.show', $this->reservation->reference);
    }
}
