<?php

namespace App\Http\Controllers;

use App\Services\QuoteNumbers;
use Illuminate\Http\JsonResponse;

class QuoteNumberController extends Controller
{
    public function __invoke(QuoteNumbers $quoteNumbers): JsonResponse
    {
        return response()->json([
            'number' => $quoteNumbers->next(),
        ]);
    }
}
