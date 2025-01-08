<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TelegramController;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/telegram/webhook', [TelegramController::class, 'webhook'])->name('telegram.webhook');

// Route::middleware(['auth'])->group(function () {
//     Route::resource('dynamic-content', DynamicContentController::class);
// });
