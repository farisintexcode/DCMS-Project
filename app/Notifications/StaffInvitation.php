<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** M01/M10: short-lived activation through the existing broker; never put tokens in audit/log channels. */
class StaffInvitation extends Notification
{
    public function __construct(#[\SensitiveParameter] private string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Activate your '.config('dcms.branding.name').' staff account')
            ->greeting('Welcome to '.config('dcms.branding.name'))
            ->line('An authorized administrator has invited you to DebtOps — Debt Collection Management System by InteXcode.')
            ->action('Activate your account', route('activation.edit', ['token' => $this->token, 'email' => $notifiable->email]))
            ->line('This single-use activation link expires in '.config('auth.passwords.users.expire').' minutes.')
            ->line('If you were not expecting this invitation, contact your administrator.');
    }
}
