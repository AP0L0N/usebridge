<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Support\Legal\LegalPages;
use Inertia\Inertia;
use Inertia\Response;

/** The public legal pages (no login, no gate). The page id comes from the route defaults, never from user input. */
class LegalPageController extends Controller
{
    public function __invoke(string $legalPage): Response
    {
        app()->setLocale(LegalPages::locale($legalPage));

        return Inertia::render('Legal/Show', LegalPages::page($legalPage));
    }
}
