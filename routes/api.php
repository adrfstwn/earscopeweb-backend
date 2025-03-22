<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VideoController;


Route::post('login', [AuthController::class, 'login']);

Route::post('videos', [VideoController::class, 'store']); // Upload video

Route::middleware('jwt.auth')->group(function () {
    Route::post('logout', [AuthController::class, 'logout']);
    Route::post('refresh', [AuthController::class, 'refresh']);

    Route::get('videos/stream/{filename}', [VideoController::class, 'streamVideo']);

    // Route untuk admin
    Route::middleware('role:admin|dokter')->group(function () {
        Route::get('pasien', [UserController::class, 'getAllPasien']);
        Route::get('dokter', [UserController::class, 'getAllDokter']);
        Route::get('users/{id}', [UserController::class, 'show']);
        Route::put('users/{id}', [UserController::class, 'update']);
        Route::delete('users/{id}', [UserController::class, 'delete']);
        Route::delete('users/{id}/force-delete', [UserController::class, 'forceDelete']);
        Route::post('users/{id}/restore', [UserController::class, 'restore']);

        Route::post('videos/{videoId}/assign/{userId}', [VideoController::class, 'assignToUser']); // Assign video ke user
        Route::patch('/videos/{videoId}', [VideoController::class, 'updateStatusVideo']);
        Route::get('videos', [VideoController::class, 'showAllVideos']); // Lihat semua video
        Route::get('videos/{videoId}', [VideoController::class, 'showById']); // Lihat detail video

    });

    // Route untuk admin
    Route::middleware('role:admin')->group(function () {
        Route::post('register/dokter', [UserController::class, 'registerDokter']);
    });

    // Route untuk dokter
    Route::middleware('role:dokter')->group(function () {
        Route::post('register/pasien', [UserController::class, 'registerPasien']);
    });

    // Route untuk pasien
    Route::middleware('role:pasien')->group(function () {
        Route::get('videos/pasien', [VideoController::class, 'showVideosByPasien']);
    });

    // Route tanpa pembatasan role
    Route::get('me', [UserController::class, 'me']);

});


