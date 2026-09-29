<?php
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Illuminate\Auth\AuthenticationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(api: __DIR__.'/../routes/api.php', commands: __DIR__.'/../routes/console.php', health: '/up', apiPrefix: 'api')
    ->withMiddleware(function (Middleware $middleware): void {})
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*'));
        
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if (!$request->is('api/*')) return null;
            return response()->json(['success'=>false,'message'=>'Unauthenticated.'], 401);
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if (!$request->is('api/*')) return null;
            return response()->json(['success'=>false,'message'=>'The submitted data is invalid.','errors'=>$e->errors()],422);
        });
        $exceptions->render(function (Throwable $e, Request $request) {
            if (!$request->is('api/*')) return null;
            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;
            $message = $status === 500 && !config('app.debug') ? 'Server error. Please try again later.' : ($e->getMessage() ?: 'Something went wrong.');
            return response()->json(['success'=>false,'message'=>$message],$status);
        });
    })->create();
