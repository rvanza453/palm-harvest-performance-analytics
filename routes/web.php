<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PerformanceController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return redirect('/admin');
});

// Admin Dashboard - Upload & Management
Route::get('/admin', [PerformanceController::class, 'index'])->name('admin.index');

// Analysis Dashboard - View & Export
Route::get('/dashboard', [PerformanceController::class, 'dashboard'])->name('dashboard.index');
