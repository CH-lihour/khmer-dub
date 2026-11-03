<?php

use App\Http\Controllers\VideoJobController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dub');

Route::controller(VideoJobController::class)->group(function () {
    Route::get('/dub', 'index')->name('dub.index');
    Route::post('/dub', 'store')->name('dub.store');
    Route::get('/dub/{job}/status', 'status')->name('dub.status');
    Route::get('/dub/{job}/download/{kind}', 'download')->whereIn('kind', ['srt', 'video'])->name('dub.download');
});
