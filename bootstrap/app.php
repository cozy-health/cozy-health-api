<?php
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Illuminate\Auth\AuthenticationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([\App\Providers\AppServiceProvider::class])
    ->withRouting(api: __DIR__.'/../routes/api.php', commands: __DIR__.'/../routes/console.php', web: __DIR__.'/../routes/web.php', apiPrefix: 'api')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(\App\Http\Middleware\SecurityHeaders::class);
        // Railway ingress terminates TLS and supplies X-Forwarded-Proto.
        // Do not trust forwarded host values for URL generation.
        $middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_PROTO);
        $middleware->append(\App\Http\Middleware\RequireHttps::class);
        $middleware->append(\App\Http\Middleware\HideDebugEndpoints::class);
        $middleware->alias(['role' => \App\Http\Middleware\RequireRole::class,
            'admin.analytics' => \App\Http\Middleware\AdminAnalyticsSession::class,
            'admin.manage' => \App\Http\Middleware\AdminManagementSession::class]);
        $middleware->api(append: [\App\Http\Middleware\RestrictAdminAnalyticsToken::class]);
        $middleware->appendToPriorityList(\Illuminate\Auth\Middleware\Authenticate::class, \App\Http\Middleware\RestrictAdminAnalyticsToken::class);
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*', 'admin/*', 'webhooks/*'));

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if (!$request->is('api/*', 'admin/*', 'webhooks/*')) return null;
            return response()->json(['success'=>false,'message'=>'Unauthenticated.'], 401);
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if (!$request->is('api/*', 'admin/*', 'webhooks/*')) return null;
            return response()->json(['success'=>false,'message'=>'The submitted data is invalid.','errors'=>$e->errors()],422);
        });
        $exceptions->render(function (Throwable $e, Request $request) {
            if (!$request->is('api/*', 'admin/*', 'webhooks/*')) return null;
            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;
            $message = $status === 500 && !config('app.debug') ? 'Server error. Please try again later.' : ($e->getMessage() ?: 'Something went wrong.');
            $headers = $e instanceof HttpExceptionInterface ? $e->getHeaders() : [];
            return response()->json(['success'=>false,'message'=>$message],$status,$headers);
        });
    })->create();
