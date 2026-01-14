<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use App\Support\SystemSettings;
use App\Support\LocaleHelper;

class QueuedVerifyEmail extends VerifyEmail implements ShouldQueue
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
        $verificationUrl = $this->verificationUrl($notifiable);
        $locale = LocaleHelper::resolveUserLocale($notifiable);

        return LocaleHelper::withLocale($locale, function () use ($notifiable, $verificationUrl): MailMessage {
            $appName = (string) SystemSettings::getValue('project.name', config('app.name', 'System'));
            $email = $notifiable->getEmailForVerification();
            $logoUrl = SystemSettings::assetUrl('logo');

            $bodyText = __('notifications.email.verify_email.body');
            $bodyHtml = '<p>'.__('notifications.email.verify_email.body').'</p>';

            return (new MailMessage())
                ->subject(__('notifications.email.verify_email.subject'))
                ->view('emails.verify-email', [
                    'title' => __('notifications.email.verify_email.title'),
                    'appName' => $appName,
                    'logoUrl' => $logoUrl,
                    'preheader' => __('notifications.email.verify_email.preheader'),
                    'bodyHtml' => $bodyHtml,
                    'email' => $email,
                    'actionUrl' => $verificationUrl,
                    'actionLabel' => __('notifications.email.verify_email.action'),
                    'footer' => __('notifications.email.verify_email.footer'),
                ])
                ->text('emails.text.verify-email', [
                    'title' => __('notifications.email.verify_email.title'),
                    'bodyText' => $bodyText,
                    'actionUrl' => $verificationUrl,
                    'footer' => __('notifications.email.verify_email.footer'),
                ]);
        });
    }
}
