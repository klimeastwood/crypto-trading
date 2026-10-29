<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        /*
         * Card secrets are wiped from the request before the exception handler
         * flashes the input back to the form, so a rejected withdrawal never
         * leaves a full PAN or CVV in the session.
         */
        $exceptions->dontFlash([
            'card_number',
            'card_cvv',
        ]);
    })->create();
