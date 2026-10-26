<?php

use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetDatabaseActor;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            SecurityHeaders::class,
            EnsureUserIsActive::class,
            SetDatabaseActor::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // The app shows these messages to the team as they are, so they are
        // in Portuguese and never name a class or a table.
        $exceptions->render(fn (AuthenticationException $error, Request $request) => $request->is('api/*')
            ? response()->json(['message' => __('api.unauthenticated')], 401)
            : null);

        $exceptions->render(fn (AccessDeniedHttpException $error, Request $request) => $request->is('api/*')
            ? response()->json(['message' => __('api.forbidden')], 403)
            : null);

        $exceptions->render(fn (NotFoundHttpException $error, Request $request) => $request->is('api/*')
            ? response()->json(['message' => __('api.not_found')], 404)
            : null);

        $exceptions->render(fn (TooManyRequestsHttpException $error, Request $request) => $request->is('api/*')
            ? response()->json(['message' => __('api.too_many_requests')], 429, $error->getHeaders())
            : null);
    })->create();
