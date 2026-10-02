<?php

use App\Http\Controllers\StationController;
use App\Http\Controllers\TrainController;
use Illuminate\Support\Facades\Route;

Route::get('/', [StationController::class, 'index'])->name('stations.index');
Route::get('/stations/{code}', [StationController::class, 'show'])->name('stations.show');

Route::get('/trains', [TrainController::class, 'search'])->name('trains.search');
Route::get('/trains/{number}', [TrainController::class, 'show'])->name('trains.show');
Route::get('/trains/{number}/map', [TrainController::class, 'map'])->name('trains.map');

Route::inertia('/map', 'LiveMap/Index')->name('map');
Route::inertia('/settings', 'Settings')->name('settings');
