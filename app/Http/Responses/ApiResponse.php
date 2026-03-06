<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;

trait ApiResponse
{
    /**
     * 200 — Successful response with data.
     */
    protected function success(mixed $data = null, string $message = 'OK', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], $status);
    }

    /**
     * 201 — Resource created.
     */
    protected function created(mixed $data = null, string $message = 'Created successfully.'): JsonResponse
    {
        return $this->success($data, $message, 201);
    }

    /**
     * 204 — No content (delete, logout).
     */
    protected function noContent(): JsonResponse
    {
        return response()->json(null, 204);
    }

    /**
     * 400 — Bad request / business logic error.
     */
    protected function badRequest(string $message = 'Bad request.'): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], 400);
    }

    /**
     * 404 — Resource not found.
     */
    protected function notFound(string $message = 'Resource not found.'): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], 404);
    }

    /**
     * 403 — Forbidden.
     */
    protected function forbidden(string $message = 'Forbidden.'): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], 403);
    }

    /**
     * 422 — Validation error (used manually when not using FormRequest).
     */
    protected function validationError(array $errors, string $message = 'Validation failed.'): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors'  => $errors,
        ], 422);
    }

    /**
     * Paginated response — wraps paginator with standard envelope.
     */
    protected function paginated(\Illuminate\Contracts\Pagination\LengthAwarePaginator $paginator, string $message = 'OK'): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => $paginator->items(),
            'meta'    => [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'last_page'    => $paginator->lastPage(),
            ],
        ]);
    }
}
