<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Session\TokenMismatchException;
use Spatie\Permission\Exceptions\UnauthorizedException as SpatieUnauthorizedException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(
            [
                \App\Http\Middleware\SetLocale::class,
                \App\Http\Middleware\MaintenanceModeMiddleware::class,
                \App\Http\Middleware\EnsureAccountIsActive::class,
                \App\Http\Middleware\EnsureSecurityStampIsValid::class,
                \App\Http\Middleware\UpdateLastSeenMiddleware::class,
                \App\Http\Middleware\AuditLogMiddleware::class,
            ],
            [
                \App\Http\Middleware\RequestIdMiddleware::class,
            ]
        );

        $middleware->alias([
            'account.active' => \App\Http\Middleware\EnsureAccountIsActive::class,
            'security.stamp' => \App\Http\Middleware\EnsureSecurityStampIsValid::class,
            'role.admin' => \App\Http\Middleware\EnsureUserHasRole::class,
        ]);

        $middleware->preventRequestsDuringMaintenance([
            '/maintenance/status',
            '/maintenance/stream',
            '/maintenance/bypass',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (Throwable $exception): void {
            if (app()->runningInConsole()) {
                return;
            }

            $request = request();
            $statusCode = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;
            $requestId = $request->headers->get('X-Request-Id') ?: $request->attributes->get('request_id');

            $userId = null;
            try {
                $userId = $request->user()?->getAuthIdentifier();
            } catch (Throwable) {
                $userId = null;
            }

            $message = $exception->getMessage();
            $message = is_string($message) && $message !== '' ? Str::limit($message, 200) : null;

            Log::warning('http.error', [
                'status' => $statusCode,
                'exception' => $exception::class,
                'message' => $message,
                'route' => optional($request->route())->getName(),
                'path' => $request->path(),
                'method' => $request->method(),
                'request_id' => $requestId,
                'user_id' => $userId,
            ]);
        });

        $exceptions->render(function (Throwable $exception, $request) {
            if ($request->expectsJson()) {
                return null;
            }

            if ($exception instanceof ValidationException) {
                return null;
            }

            if ($exception instanceof AuthenticationException) {
                return null;
            }

            if ($exception instanceof HttpResponseException) {
                return $exception->getResponse();
            }

            $statusCode = match (true) {
                $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
                $exception instanceof AuthorizationException => 403,
                $exception instanceof SpatieUnauthorizedException => 403,
                $exception instanceof TokenMismatchException => 419,
                $exception instanceof ModelNotFoundException => 404,
                default => 500,
            };

            $headers = $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : [];

            $maintenanceActive = false;
            $maintenanceData = [];
            try {
                $maintenanceActive = app()->maintenanceMode()->active();
                if ($maintenanceActive) {
                    $maintenanceData = app()->maintenanceMode()->data();
                }
            } catch (Throwable) {
                $maintenanceActive = false;
                $maintenanceData = [];
            }

            if ($statusCode === 503 && $maintenanceActive) {
                return response()->view('errors.maintenance', [
                    'statusCode' => $statusCode,
                    'requestId' => $request->headers->get('X-Request-Id'),
                    'serverNow' => now()->toIso8601String(),
                    'maintenanceData' => $maintenanceData,
                    'retryAfter' => $headers['Retry-After'] ?? ($maintenanceData['retry'] ?? null),
                ], $statusCode, $headers);
            }

            return response()->view('errors.error', [
                'exception' => $exception,
                'statusCode' => $statusCode,
                'requestId' => $request->headers->get('X-Request-Id'),
            ], $statusCode, $headers);
        });
    })->create();
