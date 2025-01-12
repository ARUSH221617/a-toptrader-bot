<?php

namespace App\Http\Controllers;

use Illuminate\Http\Client\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminMiniAppController extends Controller
{
    public function render(Request $request): Response
    {
        return Inertia::render('Admin/Dashboard');
    }
}
