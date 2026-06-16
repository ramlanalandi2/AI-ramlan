<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\AiChatController;
use App\Http\Controllers\WhatsappWebhookController;

Route::post('/test-chat', [AiChatController::class, 'testChat']);
Route::post('/generate-prompt', [AiChatController::class, 'getPrompt']);
Route::post('/save-reply', [AiChatController::class, 'saveReply']);

Route::get('/whatsapp/webhook', [WhatsappWebhookController::class, 'verify']);
Route::post('/whatsapp/webhook', [WhatsappWebhookController::class, 'receive']);

// Facebook Messenger Routes
Route::get('/facebook/webhook', [\App\Http\Controllers\FacebookWebhookController::class, 'verify']);
Route::post('/facebook/webhook', [\App\Http\Controllers\FacebookWebhookController::class, 'handle']);

// Telegram Routes
Route::post('/telegram/webhook', [\App\Http\Controllers\TelegramBotController::class, 'handle']);
Route::get('/telegram/set-webhook', [\App\Http\Controllers\TelegramBotController::class, 'setWebhook']);

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
