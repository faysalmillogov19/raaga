<?php

namespace App\Notifications;

use App\Models\ReservationBoutique;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Identité de l'attributaire d'une boutique : à corriger avant la date limite (rejetée, à mettre à jour ou jamais
 * soumise), puis réservation annulée faute de correction. Notification dans « Mon espace » et e-mail si le compte a une
 * adresse (le SMS part en plus, depuis IdentitesAttributaires).
 */
class IdentiteAttributaireCitoyen extends Notification
{
    /** @param  string  $evenement  a_corriger | annulee */
    public function __construct(private readonly ReservationBoutique $reservation, private readonly string $evenement) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $notifiable->email ? ['database', 'mail'] : ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['message' => $this->message(), 'url' => $this->url(), 'type' => 'identite_attributaire_'.$this->evenement, 'reference' => $this->reservation->reference];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('public.boutiques.identite.notifications.sujet', ['boutique' => (string) $this->reservation->boutique?->code]))
            ->greeting(__('admin.utilisateurs.mail.bonjour', ['nom' => $notifiable->name]))
            ->line($this->message())
            ->action(__('public.boutiques.identite.bouton'), $this->url())
            ->salutation(__('admin.utilisateurs.mail.signature', ['plateforme' => plateforme('identite.nomPlateforme')]));
    }

    public function message(): string
    {
        return __('public.boutiques.identite.notifications.'.$this->evenement, [
            'boutique' => (string) $this->reservation->boutique?->code,
            'date' => $this->reservation->kyc_a_corriger_avant?->translatedFormat('j F Y à H\hi') ?? '',
        ]);
    }

    private function url(): string
    {
        return $this->evenement === 'a_corriger' ? route('espace.verification') : route('boutiques.suivi', $this->reservation->reference);
    }
}
