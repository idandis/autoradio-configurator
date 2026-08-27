<?php

namespace App\Http\Controllers;

use App\Models\DismissedPostImportTask;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class DismissPostImportTasksController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        abort_unless(Schema::hasTable('dismissed_post_import_tasks'), 409, 'Aggiorna prima il database.');

        $data = $request->validate([
            'fingerprint' => ['required', 'string', 'size:64'],
        ]);

        DismissedPostImportTask::firstOrCreate($data);

        return back()->with('status', 'Nota delle attività post-importazione cancellata.');
    }
}
