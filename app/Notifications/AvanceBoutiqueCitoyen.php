<?php

namespace App\Notifications;

use App\Models\DecisionAvance;
use App\Models\ReservationBoutique;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Avance d'une boutique réservée : réservation annulée automatiquement (avance non payée dans le délai), puis retenue
 * confirmée sur la caution (montant remboursé, ou rien à 100 %). Notification dans « Mon espace » et e-mail si le
 * compte a une adresse.
 */
class AvanceBoutiqueCitoyen extends Notification
{
    /** @param  string  $evenement  annulee_auto | retenue */
    public function __construct(
        private readonly ReservationBoutique $reservation,
        private readonly string $evenement,
        private readonly ?DecisionAvance $decision = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $notifiable->email ? ['database', 'mail'] : ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['message' => $this->message(), 'url' => $this->url(), 'type' => 'avance_'.$this->evenement, 'reference' => $this->reservation->reference];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('public.boutiques.avance.notifications.sujet', ['reference' => $this->reservation->reference]))
            ->greeting(__('admin.utilisateurs.mail.bonjour', ['nom' => $notifiable->name]))
            ->line($this->message())
            ->action(__('public.relances.bouton'), $this->url())
            ->salutation(__('admin.utilisateurs.mail.signature', ['plateforme' => plateforme('identite.nomPlateforme')]));
    }

    public function message(): string
    {
        $r = $this->reservation;

        $cle = $this->evenement === 'retenue' && (int) $this->decision?->retenue_pct === 100 ? 'retenue_totale' : $this->evenement;

        return __('public.boutiques.avance.notifications.'.$cle, [
            'reference' => $r->reference, 'boutique' => (string) $r->boutique?->code,
            'date' => $r->avance_due_le?->translatedFormat('j F Y à H\hi') ?? '',
            'montant' => $this->decision ? format_fcfa($this->decision->montantRembourse()) : '',
            'pct' => (int) $this->decision?->retenue_pct,
        ]);
    }

    private function url(): string
    {
        return route('boutiques.suivi', $this->reservation->reference);
    }
}
