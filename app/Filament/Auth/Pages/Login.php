<?php

namespace App\Filament\Auth\Pages;

use App\Enums\AccountStatus;
use App\Models\User;
use App\Support\AuditLogWriter;
use App\Support\NotificationDeliveryLogger;
use App\Support\SecurityAlert;
use App\Support\SystemSettings;
use Carbon\Carbon;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

class Login extends BaseLogin
{
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getEmailFormComponent(),
                $this->getPasswordFormComponent(),
                TextInput::make('otp')
                    ->label('Kode OTP Email')
                    ->numeric()
                    ->minLength(6)
                    ->maxLength(6)
                    ->autocomplete('one-time-code')
                    ->visible(fn (): bool => session()->has('auth.otp_user_id'))
                    ->helperText(function (): string {
                        $email = (string) (session()->get('auth.otp_user_email') ?? '');
                        return $email !== '' ? "Masukkan kode OTP yang dikirim ke {$email}." : 'Masukkan kode OTP dari email Anda.';
                    }),
                $this->getRememberFormComponent(),
            ])
            ->statePath('data');
    }

    public function authenticate(): ?LoginResponse
    {
        try {
            $this->rateLimit(5);
        } catch (\DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }

        $data = $this->form->getState();

        /** @var SessionGuard $authGuard */
        $authGuard = Filament::auth();
        $authProvider = $authGuard->getProvider(); /** @phpstan-ignore-line */
        $credentials = $this->getCredentialsFromFormData($data);

        $user = $authProvider->retrieveByCredentials($credentials);

        if ((! $user) || (! $authProvider->validateCredentials($user, $credentials))) {
            $this->clearOtpSession();
            $this->fireFailedEvent($authGuard, $user, $credentials);
            $this->throwFailureValidationException();
        }

        if ($user instanceof User && $this->requiresEmailOtp($user)) {
            if (! $this->validateOtp($user, (string) ($data['otp'] ?? ''))) {
                $this->sendOtpIfNeeded($user);
                throw ValidationException::withMessages([
                    'data.otp' => 'Kode OTP dikirim ke email Anda. Silakan masukkan kode tersebut.',
                ]);
            }

            $this->clearOtpSession();
            $this->auditOtpEvent('auth_otp_verified', $user);
        }

        if (! $authGuard->attemptWhen($credentials, function (Authenticatable $user): bool {
            if (! ($user instanceof \Filament\Models\Contracts\FilamentUser)) {
                return true;
            }

            return $user->canAccessPanel(Filament::getCurrentOrDefaultPanel());
        }, $data['remember'] ?? false)) {
            $this->fireFailedEvent($authGuard, $user, $credentials);
            $this->throwFailureValidationException();
        }

        session()->regenerate();

        return app(LoginResponse::class);
    }

    private function requiresEmailOtp(User $user): bool
    {
        return (bool) $user->two_factor_enabled
            && $user->two_factor_method === 'email'
            && filled($user->email);
    }

    private function sendOtpIfNeeded(User $user): void
    {
        $otpKey = 'auth:otp:' . $user->getAuthIdentifier();
        $metaKey = $otpKey . ':meta';

        $existing = Cache::get($otpKey);
        if (is_array($existing) && isset($existing['hash'])) {
            $this->storeOtpSession($user);
            return;
        }

        $otp = (string) random_int(100000, 999999);
        $hash = hash('sha256', $otp);

        Cache::put($otpKey, ['hash' => $hash], now()->addMinutes(5));
        Cache::put($metaKey, ['sent_at' => now()->toDateTimeString()], now()->addMinutes(10));

        $this->storeOtpSession($user);
        $this->sendOtpEmail($user, $otp);
        $this->auditOtpEvent('auth_otp_sent', $user);
    }

    private function validateOtp(User $user, string $input): bool
    {
        $input = trim($input);
        if ($input === '') {
            return false;
        }

        $otpKey = 'auth:otp:' . $user->getAuthIdentifier();
        $payload = Cache::get($otpKey);
        if (! is_array($payload) || empty($payload['hash'])) {
            return false;
        }

        $hash = hash('sha256', $input);
        $valid = hash_equals($payload['hash'], $hash);
        if ($valid) {
            Cache::forget($otpKey);
        }

        return $valid;
    }

    private function sendOtpEmail(User $user, string $otp): void
    {
        $fromAddress = (string) SystemSettings::getValue('notifications.email.auth_from_address', '')
            ?: (string) SystemSettings::getValue('notifications.email.from_address', '');
        $fromName = (string) SystemSettings::getValue('notifications.email.auth_from_name', '')
            ?: (string) SystemSettings::getValue('notifications.email.from_name', '');

        $body = "Kode OTP login Anda: {$otp}\nBerlaku 5 menit.";

        try {
            SystemSettings::applyMailConfig('auth');
            Mail::raw($body, function ($mail) use ($user, $fromAddress, $fromName): void {
                $mail->to($user->email)->subject('Kode OTP Login');
                if ($fromAddress !== '') {
                    $mail->from($fromAddress, $fromName !== '' ? $fromName : null);
                }
            });

            NotificationDeliveryLogger::log(
                $user,
                null,
                'mail',
                'sent',
                [
                    'notification_type' => 'auth_otp',
                    'recipient' => $user->email,
                    'summary' => 'OTP login email',
                    'request_id' => request()?->headers->get('X-Request-Id'),
                ],
            );
        } catch (\Throwable $error) {
            NotificationDeliveryLogger::log(
                $user,
                null,
                'mail',
                'failed',
                [
                    'notification_type' => 'auth_otp',
                    'recipient' => $user->email,
                    'summary' => 'OTP login email',
                    'error_message' => $error->getMessage(),
                    'request_id' => request()?->headers->get('X-Request-Id'),
                ],
            );
        }
    }

    private function storeOtpSession(User $user): void
    {
        session()->put('auth.otp_user_id', $user->getAuthIdentifier());
        session()->put('auth.otp_user_email', $user->email);
    }

    private function clearOtpSession(): void
    {
        session()->forget('auth.otp_user_id');
        session()->forget('auth.otp_user_email');
    }

    private function auditOtpEvent(string $action, User $user): void
    {
        $request = request();
        $requestId = $request?->headers->get('X-Request-Id') ?: (string) \Illuminate\Support\Str::uuid();
        $sessionId = $request?->hasSession() ? $request->session()->getId() : null;

        AuditLogWriter::writeAudit([
            'user_id' => $user->getAuthIdentifier(),
            'action' => $action,
            'auditable_type' => $user::class,
            'auditable_id' => $user->getAuthIdentifier(),
            'old_values' => null,
            'new_values' => null,
            'ip_address' => $request?->ip(),
            'user_agent' => (string) ($request?->userAgent() ?? ''),
            'url' => $request?->fullUrl(),
            'route' => (string) optional($request?->route())->getName(),
            'method' => $request?->method(),
            'status_code' => null,
            'request_id' => $requestId,
            'session_id' => $sessionId,
            'duration_ms' => null,
            'context' => [
                'event' => $action,
                'identity' => $user->email ?? $user->username,
            ],
            'created_at' => now(),
        ]);

        SecurityAlert::dispatch($action, [
            'title' => $action === 'auth_otp_sent' ? 'OTP sent' : 'OTP verified',
            'user_id' => $user->getAuthIdentifier(),
            'email' => $user->email,
            'username' => $user->username,
        ], $request);
    }
    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('username')
            ->label('Username')
            ->required()
            ->autocomplete('username')
            ->maxLength(50);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function getCredentialsFromFormData(array $data): array
    {
        return [
            'username' => $data['username'],
            'password' => $data['password'],
        ];
    }

    protected function throwFailureValidationException(): never
    {
        $username = data_get($this->form->getState(), 'username');
        $user = is_string($username) ? User::where('username', $username)->first() : null;

        if ($user && $user->account_status !== AccountStatus::Active) {
            $reason = $user->blocked_reason ? strip_tags($user->blocked_reason) : 'Akun sedang tidak aktif.';
            throw ValidationException::withMessages([
                'data.username' => $reason,
            ]);
        }

        if ($user && $user->blocked_until && $user->blocked_until->isFuture()) {
            $until = $user->blocked_until->timezone(config('app.timezone', 'UTC'))->format('Y-m-d H:i');
            $message = 'Akun terkunci hingga ' . $until . '.';
            throw ValidationException::withMessages([
                'data.username' => $message,
            ]);
        }

        throw ValidationException::withMessages([
            'data.username' => __('filament-panels::auth/pages/login.messages.failed'),
        ]);
    }
}
