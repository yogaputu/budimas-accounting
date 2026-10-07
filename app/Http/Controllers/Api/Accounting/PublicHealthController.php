<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

final class PublicHealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'service' => 'budimas-accounting',
            'status' => 'ok',
        ]);
    }
}
