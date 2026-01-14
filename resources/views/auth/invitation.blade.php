<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ __('invitation.title') }}</title>
        <style>
            :root {
                color-scheme: light;
                --bg: #f5f7fb;
                --card: #ffffff;
                --ink: #1b1b1f;
                --muted: #6d6d7a;
                --accent: #3b6df6;
                --border: #e4e7f2;
            }
            body {
                margin: 0;
                font-family: "Manrope", "Segoe UI", sans-serif;
                background: var(--bg);
                color: var(--ink);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 32px;
            }
            .card {
                width: min(520px, 100%);
                background: var(--card);
                border-radius: 18px;
                border: 1px solid var(--border);
                padding: 28px;
                box-shadow: 0 20px 60px rgba(20, 25, 35, 0.08);
            }
            h1 {
                margin: 0 0 8px;
                font-size: 26px;
            }
            .meta {
                color: var(--muted);
                font-size: 13px;
                margin-top: 12px;
            }
            .alert {
                background: #ffeaea;
                color: #a42828;
                border: 1px solid #f1b5b5;
                padding: 10px 12px;
                border-radius: 10px;
                font-size: 13px;
                margin-bottom: 16px;
            }
            form {
                display: grid;
                gap: 14px;
                margin-top: 18px;
            }
            label {
                font-size: 13px;
                font-weight: 600;
            }
            input {
                width: 100%;
                padding: 12px 14px;
                border-radius: 12px;
                border: 1px solid var(--border);
                font-size: 15px;
            }
            button {
                margin-top: 6px;
                padding: 12px 16px;
                border-radius: 999px;
                border: none;
                background: var(--accent);
                color: #fff;
                font-weight: 600;
                cursor: pointer;
            }
            .email {
                font-size: 14px;
                color: var(--muted);
            }
        </style>
    </head>
    <body>
        <main class="card">
            <h1>{{ __('invitation.title') }}</h1>
            <p class="email">{{ __('invitation.email', ['email' => $email]) }}</p>

            @if ($errors->any())
                <div class="alert">
                    @foreach ($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            @endif

            <form method="POST" action="{{ $formAction }}">
                @csrf
                <div>
                    <label for="username">{{ __('invitation.fields.username') }}</label>
                    <input id="username" name="username" type="text" value="{{ old('username') }}" required>
                </div>
                <div>
                    <label for="password">{{ __('invitation.fields.password') }}</label>
                    <input id="password" name="password" type="password" required>
                </div>
                <div>
                    <label for="password_confirmation">{{ __('invitation.fields.password_confirmation') }}</label>
                    <input id="password_confirmation" name="password_confirmation" type="password" required>
                </div>
                <button type="submit">{{ __('invitation.submit') }}</button>
            </form>

            @if ($expiresAt)
                <p class="meta">{{ __('invitation.expires', ['time' => $expiresAt->format('Y-m-d H:i')]) }}</p>
            @endif
        </main>
    </body>
</html>
