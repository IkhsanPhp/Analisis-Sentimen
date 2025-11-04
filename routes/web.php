<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SentimentController;

Route::get('/', [SentimentController::class, 'index'])->name('sentiment.index');
Route::post('/train', [SentimentController::class, 'train'])->name('sentiment.train');
Route::post('/predict', [SentimentController::class, 'predict'])->name('sentiment.predict');
Route::get('/download-results', [SentimentController::class, 'downloadResults'])->name('sentiment.download');
