<?php

use App\Http\Controllers\AdminMiniAppController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TelegramController;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
use Inertia\Inertia;

Route::post('/telegram/webhook', [TelegramController::class, 'webhook'])
    ->name('telegram.webhook')
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);

Route::get('telegram/app/admin', [AdminMiniAppController::class, 'render'])->name('telegram.mini_app.admin');

Route::get('/set', function () {
    $botToken = env('TELEGRAM_BOT_TOKEN');
    $webhookUrl = env('TELEGRAM_WEBHOOK_URL');

    if (empty($botToken) || empty($webhookUrl)) {
        return response()->json(['error' => 'Bot token or webhook URL is not set'], 400, [], JSON_PRETTY_PRINT);
    }

    $response = Http::post("https://api.telegram.org/bot{$botToken}/setWebhook", [
        'url' => $webhookUrl
    ]);

    if ($response->successful()) {
        return response()->json($response->json(), 200, [], JSON_PRETTY_PRINT);
    } else {
        return response()->json(['error' => 'Unable to set webhook'], $response->status(), [], JSON_PRETTY_PRINT);
    }
})->name('telegram.set');

Route::get('/delete', function () {
    $botToken = env('TELEGRAM_BOT_TOKEN');

    if (empty($botToken)) {
        return response()->json(['error' => 'Bot token is not set'], 400, [], JSON_PRETTY_PRINT);
    }

    $response = Http::post("https://api.telegram.org/bot{$botToken}/deleteWebhook");

    if ($response->successful()) {
        return response()->json(['success' => 'Webhook deleted successfully'], 200, [], JSON_PRETTY_PRINT);
    } else {
        return response()->json(['error' => 'Unable to delete webhook'], $response->status(), [], JSON_PRETTY_PRINT);
    }
})->name('telegram.delete');

Route::get('/info', function () {
    $botToken = env('TELEGRAM_BOT_TOKEN');
    $response = Http::get("https://api.telegram.org/bot{$botToken}/GetWebhookInfo");
    if ($response->successful()) {
        return response()->json($response->json(), 200, [], JSON_PRETTY_PRINT);
    } else {
        return response()->json(['error' => 'Unable to retrieve webhook info'], $response->status(), [], JSON_PRETTY_PRINT);
    }
})->name('telegram.info');

Route::get('/db/{table}', function ($table) {
    if (!Schema::hasTable($table)) {
        return response()->json(['error' => 'Table not found'], 404, [], JSON_PRETTY_PRINT);
    }

    $data = DB::table($table)->get();
    return response()->json($data, 200, [], JSON_PRETTY_PRINT);
})->name('db.show');

Route::get('/db/{table}/clear', function ($table) {
    if (!Schema::hasTable($table)) {
        return response()->json(['error' => 'Table not found'], 404, [], JSON_PRETTY_PRINT);
    }

    DB::table($table)->truncate();
    return response()->json(['success' => 'Table rows cleared'], 200, [], JSON_PRETTY_PRINT);
})->name('db.clear');

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
