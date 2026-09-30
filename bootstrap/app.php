<?php

use App\Exceptions\HBX\HbxApiException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trimStrings(except: ['rate_key']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (HbxApiException $exception, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'heading' => $exception->heading(),
                    'code' => $exception->supplierCode,
                    'message' => $exception->getMessage(),
                ], $exception->httpStatus && $exception->httpStatus >= 400 ? $exception->httpStatus : 422);
            }

            return redirect()
                ->back(fallback: route('dashboard'))
                ->withInput()
                ->with('hbx_error', $exception->viewData());
        });
    })->create();
