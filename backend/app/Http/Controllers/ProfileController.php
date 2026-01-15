<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data' => [
                'api_calls_30d' => $user?->api_calls_30d ?? 0,
                'last_login_at' => $user?->last_login_at,
                'created_at' => $user?->created_at,
            ],
        ]);
    }
}
