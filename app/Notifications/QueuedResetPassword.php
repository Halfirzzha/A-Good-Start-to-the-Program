<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use App\Support\SystemSettings;
use App\Support\LocaleHelper;

class QueuedResetPassword extends ResetPassword implements ShouldQueue
{
    use Queueable;

    /**
     * @return array<string, string>
     */
    public function viaQueues(): array
    {
        return ['mail' => 'emails'];
    }

    public function toMail($notifiable): MailMessage
    {
        $resetUrl = $this->resetUrl($notifiable);
        $locale = LocaleHelper::resolveUserLocale($notifiable);

        return LocaleHelper::withLocale($locale, function () use ($notifiable, $resetUrl): MailMessage {
            $appName = (string) SystemSettings::getValue('project.name', config('app.name', 'System'));
            $expires = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');
            $expiresLabel = $expires ? __('notifications.email.password_reset.expires', ['minutes' => $expires]) : __('notifications.email.password_reset.expires_fallback');
            $logoUrl = SystemSettings::assetUrl('logo');

            $bodyText = __('notifications.email.password_reset.body');
            $bodyHtml = '<p>'.__('notifications.email.password_reset.body').'</p>';

            return (new MailMessage())
                ->subject(__('notifications.email.password_reset.subject'))
                ->view('emails.password-reset', [
                    'title' => __('notifications.email.password_reset.title'),
                    'appName' => $appName,
                    'logoUrl' => $logoUrl,
                    'preheader' => __('notifications.email.password_reset.preheader'),
                    'bodyHtml' => $bodyHtml,
                    'expiresLabel' => $expiresLabel,
                    'email' => $notifiable->getEmailForPasswordReset(),
                    'actionUrl' => $resetUrl,
                    'actionLabel' => __('notifications.email.password_reset.action'),
                    'footer' => __('notifications.email.password_reset.footer'),
                ])
                ->text('emails.text.password-reset', [
                    'title' => __('notifications.email.password_reset.title'),
                    'bodyText' => $bodyText,
                    'expiresLabel' => $expiresLabel,
                    'actionUrl' => $resetUrl,
                    'footer' => __('notifications.email.password_reset.footer'),
                ]);
        });
    }
}
