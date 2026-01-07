<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\DashboardController;

Route::get('/health', [HealthController::class, 'index']);
Route::get('/dashboard', [DashboardController::class, 'index']);
