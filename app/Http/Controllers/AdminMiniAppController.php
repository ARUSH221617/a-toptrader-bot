<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class AdminMiniAppController extends Controller
{
    public function render()
    {
        return view('telegram.app.admin');
    }
}
