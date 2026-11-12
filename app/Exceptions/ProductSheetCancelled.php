<?php

namespace App\Exceptions;

use Illuminate\Http\Request;
use RuntimeException;

class ProductSheetCancelled extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Elaborazione della scheda interrotta dall’utente.');
    }

    public function render(Request $request)
    {
        return $request->expectsJson() && !$request->header('X-Inertia')
            ? response()->json(['message' => $this->getMessage()], 409)
            : back()->withErrors(['processing' => $this->getMessage()]);
    }
}
