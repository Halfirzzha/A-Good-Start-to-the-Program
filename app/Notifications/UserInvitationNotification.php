<?php

namespace App\Notifications;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use App\Support\SystemSettings;
use App\Support\LocaleHelper;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\URL;

class UserInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public bool $afterCommit = true;

    public function __construct(
        public string $token,
        public ?\DateTimeInterface $expiresAt = null
    ) {
    }

    /**
     * @param  mixed  $notifiable
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    /**
     * @return array<string, string>
     */
    public function viaQueues(): array
    {
        return ['mail' => 'emails'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $expiresAt = $this->expiresAt;
        if (! $expiresAt) {
            $days = (int) config('security.invitation_expires_days', 5);
            $expiresAt = $days > 0 ? now()->addDays($days) : null;
        }

        $url = $expiresAt
            ? URL::temporarySignedRoute('invitation.show', $expiresAt, ['token' => $this->token])
            : URL::signedRoute('invitation.show', ['token' => $this->token]);

        $locale = LocaleHelper::resolveUserLocale($notifiable);

        return LocaleHelper::withLocale($locale, function () use ($expiresAt, $url): MailMessage {
            $appName = (string) SystemSettings::getValue('project.name', config('app.name', 'System'));
            $expiresLabel = $expiresAt?->toDateTimeString() ?? __('notifications.email.invitation.expires_fallback');
            $logoUrl = SystemSettings::assetUrl('logo');
            $bodyText = __('notifications.email.invitation.body');
            $bodyHtml = '<p>'.__('notifications.email.invitation.body').'</p>';

            return (new MailMessage())
                ->subject(__('notifications.email.invitation.subject'))
                ->view('emails.invitation', [
                    'title' => __('notifications.email.invitation.title'),
                    'appName' => $appName,
                    'logoUrl' => $logoUrl,
                    'preheader' => __('notifications.email.invitation.preheader'),
                    'bodyHtml' => $bodyHtml,
                    'expiresLabel' => $expiresLabel,
                    'actionUrl' => $url,
                    'actionLabel' => __('notifications.email.invitation.action'),
                    'footer' => __('notifications.email.invitation.footer'),
                ])
                ->text('emails.text.invitation', [
                    'title' => __('notifications.email.invitation.title'),
                    'bodyText' => $bodyText,
                    'expiresLabel' => $expiresLabel,
                    'actionUrl' => $url,
                    'footer' => __('notifications.email.invitation.footer'),
                ]);
        });
    }
}
