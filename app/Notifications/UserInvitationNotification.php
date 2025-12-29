<?php

namespace App\Notifications;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\URL;

class UserInvitationNotification extends Notification implements ShouldQueue
{
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

        $message = (new MailMessage())
            ->subject('Undangan Aktivasi Akun')
            ->greeting('Halo!')
            ->line('Akun Anda telah dibuat. Silakan verifikasi email dan atur username serta password Anda.')
            ->action('Aktifkan Akun', $url);

        if ($expiresAt) {
            $message->line('Tautan ini berlaku hingga '.$expiresAt->toDateTimeString().'.');
        }

        return $message;
    }
}
