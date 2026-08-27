<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class DismissPostImportTasksController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'fingerprint' => ['required', 'string', 'size:64'],
        ]);

        Cache::forever('post-import-tasks:dismissed:'.$data['fingerprint'], true);

        return back()->with('status', 'Nota delle attività post-importazione cancellata.');
    }
}
