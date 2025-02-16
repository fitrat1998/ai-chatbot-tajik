<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\TelegramBotController;

Route::get('/telegram-webhook', [TelegramBotController::class, 'handle']);
