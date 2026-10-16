<?php

namespace App\Notifications;

use App\Models\CandidatureBoutique;
use App\Models\Relance;
use App\Models\Souscription;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Relance d'un paiement attendu (souscription d'actions ou caution d'une candidature aux boutiques) : notification
 * dans « Mon espace » et e-mail si le compte a une adresse. 24 h avant la fin du délai, à l'expiration ou à la main.
 */
class RelancePaiement extends Notification
{
    public function __construct(
        private readonly Souscription|CandidatureBoutique $objet,
        private readonly string $type,
        private readonly ?string $complement = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $notifiable->email ? ['database', 'mail'] : ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['message' => $this->message(), 'url' => $this->url(), 'type' => 'relance_'.strtolower($this->type), 'reference' => $this->objet->reference];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__('public.relances.sujet', ['reference' => $this->objet->reference]))
            ->greeting(__('admin.utilisateurs.mail.bonjour', ['nom' => $notifiable->name]))
            ->line($this->message());
        if ($this->complement) {
            $mail->line($this->complement);
        }

        return $mail->action(__('public.relances.bouton'), $this->url())
            ->salutation(__('admin.utilisateurs.mail.signature', ['plateforme' => plateforme('identite.nomPlateforme')]));
    }

    private function message(): string
    {
        $objet = $this->objet instanceof Souscription ? __('public.relances.objets.souscription') : __('public.relances.objets.caution');

        return __('public.relances.'.strtolower($this->type), [
            'objet' => $objet, 'reference' => $this->objet->reference,
            'echeance' => $this->objet->expire_le?->translatedFormat('j F Y à H\hi') ?? '',
        ]);
    }

    private function url(): string
    {
        if ($this->type === Relance::EXPIRATION) {
            return route('espace');
        }

        return $this->objet instanceof Souscription
            ? route('paiement.show', $this->objet->reference)
            : route('boutiques.caution.show', $this->objet->reference);
    }
}
