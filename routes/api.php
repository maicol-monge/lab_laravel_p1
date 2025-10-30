<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// API routes for empleados
Route::prefix('v1')->group(function () {
    Route::apiResource('empleados', App\Http\Controllers\EmpleadoController::class);
    Route::get('empleados/estadisticas', [App\Http\Controllers\EmpleadoController::class, 'estadisticas']);
    Route::get('empleados/{empleado}/calculos', [App\Http\Controllers\EmpleadoController::class, 'calculos']);
});
