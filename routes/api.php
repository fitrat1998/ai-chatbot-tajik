<?php


use App\Http\Controllers\TelegramBotController; // TO‘G‘RI YO‘L
use Illuminate\Support\Facades\Route;

Route::post('/telegram-bot', [TelegramBotController::class, 'handle']);



