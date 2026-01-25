<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
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
     * Register the exception handling callbacks for the application.
     */
    /**
     * Report or log an exception.
     */
    public function report(Throwable $e): void
    {
        // Explicitly log all exceptions before parent handles it
        try {
            Log::error('Exception reported', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'class' => get_class($e),
            ]);
        } catch (\Exception $logException) {
            // If logging fails, at least try to write to error_log
            error_log('Failed to log exception: ' . $logException->getMessage());
            error_log('Original exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        }

        parent::report($e);
    }

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            // Additional logging in reportable callback
            try {
                Log::error('Exception in reportable callback', [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
            } catch (\Exception $logException) {
                error_log('Failed to log in reportable: ' . $logException->getMessage());
            }
        });
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

        // Handle HTTP exceptions
        if ($e instanceof HttpException) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'An error occurred',
                'errors' => null,
            ], $e->getStatusCode());
        }

        // Handle all other exceptions
        // Log the exception before rendering (in case report wasn't called)
        try {
            Log::error('Unhandled exception in Handler::render', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'class' => get_class($e),
                'request_url' => $request->fullUrl(),
                'request_method' => $request->method(),
            ]);
        } catch (\Exception $logException) {
            error_log('Failed to log in render: ' . $logException->getMessage());
            error_log('Original exception: ' . $e->getMessage());
        }

        $statusCode = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;
        $message = config('app.debug') ? $e->getMessage() : 'An unexpected error occurred';

        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => config('app.debug') ? [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ] : null,
        ], $statusCode);
    }
}
