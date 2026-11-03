<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\VideoJobController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dub');

Route::middleware('auth')->group(function () {
    Route::controller(VideoJobController::class)->group(function () {
        Route::get('/dub', 'index')->name('dub.index');
        Route::post('/dub', 'store')->name('dub.store');
        Route::get('/dub/{job}/status', 'status')->name('dub.status');
        Route::get('/dub/{job}/download/{kind}', 'download')->whereIn('kind', ['srt', 'video'])->name('dub.download');
    });

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
