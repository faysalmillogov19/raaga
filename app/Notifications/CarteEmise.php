<?php

namespace App\Notifications;

use App\Enums\TypeCarte;
use App\Models\Carte;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Carte d'accès émise (ou réactivée) : notification avec un lien direct vers « Ma carte », et e-mail s'il existe. */
class CarteEmise extends Notification
{
    use Queueable;

    public function __construct(private readonly Carte $carte) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $notifiable->email ? ['database', 'mail'] : ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['message' => $this->message(), 'url' => self::url($this->carte), 'type' => 'carte_emise', 'carte' => $this->carte->numero];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('public.cartes.notification.sujet'))
            ->greeting(__('admin.utilisateurs.mail.bonjour', ['nom' => $notifiable->name]))
            ->line($this->message())
            ->action(__('public.cartes.notification.voir'), self::url($this->carte));
    }

    /** Lien direct vers « Ma carte » : espace du citoyen, ou back-office pour le personnel. */
    public static function url(Carte $carte): string
    {
        return $carte->type === TypeCarte::Personnel ? route('admin.carte') : route('espace.carte', ['type' => strtolower($carte->type->value)]);
    }

    private function message(): string
    {
        return __('public.cartes.notification.message', ['type' => $this->carte->type->label(), 'numero' => $this->carte->numero]);
    }
}
