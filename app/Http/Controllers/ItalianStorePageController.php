<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ItalianStorePageController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $pages = json_decode(file_get_contents(resource_path('data/italian-store-pages.json')), true, 512, JSON_THROW_ON_ERROR);
        $slug = $request->route('slug');
        abort_unless(isset($pages[$slug]), 404);
        app()->setLocale('it');
        $request->session()->put('locale', 'it');

        return Inertia::render('ItalianStorePage', ['page' => $pages[$slug], 'slug' => $slug]);
    }
}
