<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\Access\AuthorizationException;
use App\Support\SafeLog;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Exceptions that should not be reported (expected 404s, etc.).
     *
     * @var array<int, class-string<\Throwable>>
     */
    protected $dontReport = [
        ModelNotFoundException::class,
    ];

    /**
     * Report or log an exception.
     */
    public function report(Throwable $e): void
    {
        if ($this->shouldntReport($e)) {
            return;
        }

        SafeLog::error('Exception reported', [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'class' => get_class($e),
        ]);

        try {
            parent::report($e);
        } catch (Throwable) {
            // Prevent recursive failures when storage/logs is not writable.
        }
    }

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        //
    }

    /**
     * Render an exception into an HTTP response.
     */
    public function render($request, Throwable $e): JsonResponse|\Symfony\Component\HttpFoundation\Response
    {
        // Handle authentication exceptions (401)
        if ($e instanceof AuthenticationException) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        // Handle authorization exceptions (403)
        if ($e instanceof AuthorizationException) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'This action is unauthorized',
            ], 403);
        }

        // Handle validation exceptions
        if ($e instanceof ValidationException) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        }

        // Handle model not found (e.g. workspace/project deleted or invalid ID)
        if ($e instanceof ModelNotFoundException) {
            $model = class_basename($e->getModel());
            $message = $model === 'Project'
                ? 'Workspace not found. It may have been deleted or you no longer have access.'
                : 'Resource not found.';
            return response()->json([
                'success' => false,
                'message' => $message,
            ], 404);
        }

        // Handle HTTP exceptions
        if ($e instanceof HttpException) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'An error occurred',
                'errors' => null,
            ], $e->getStatusCode());
        }

        // Handle all other exceptions — never leak internal paths/messages in production.
        SafeLog::error('Unhandled exception in Handler::render', [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'class' => get_class($e),
            'request_url' => $request->fullUrl(),
            'request_method' => $request->method(),
        ]);

        $statusCode = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;
        $message = config('app.debug')
            ? ($e->getMessage() ?: 'An unexpected error occurred')
            : 'An unexpected error occurred';

        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => config('app.debug') ? [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ] : null,
        ], $statusCode);
    }
}
