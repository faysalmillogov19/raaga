<?php

namespace App\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Identité d'un investisseur : à corriger avant la date limite, actions gelées faute de correction, puis dégelées une
 * fois l'identité validée. Notification dans « Mon espace » et e-mail si le compte a une adresse (le SMS part en plus,
 * depuis IdentitesInvestisseurs).
 */
class IdentiteInvestisseurCitoyen extends Notification
{
    /** @param  string  $evenement  a_corriger | gelees | degelees */
    public function __construct(private readonly string $evenement, private readonly ?CarbonInterface $avant) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $notifiable->email ? ['database', 'mail'] : ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['message' => $this->message(), 'url' => $this->url(), 'type' => 'identite_investisseur_'.$this->evenement];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('public.invest.identite.notifications.sujet_'.$this->evenement))
            ->greeting(__('admin.utilisateurs.mail.bonjour', ['nom' => $notifiable->name]))
            ->line($this->message())
            ->action(__('public.invest.identite.bouton_'.($this->evenement === 'degelees' ? 'espace' : 'verifier')), $this->url())
            ->salutation(__('admin.utilisateurs.mail.signature', ['plateforme' => plateforme('identite.nomPlateforme')]));
    }

    public function message(): string
    {
        return __('public.invest.identite.notifications.'.$this->evenement, ['date' => $this->avant?->translatedFormat('j F Y à H\hi') ?? '']);
    }

    private function url(): string
    {
        return $this->evenement === 'degelees' ? route('espace', ['onglet' => 'actions']) : route('espace.verification');
    }
}
