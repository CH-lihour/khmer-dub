<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\VideoJobController;
use App\Http\Middleware\SetLanguage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;

Route::redirect('/', '/dub');

// The language switch, also for guests. The choice is kept in a cookie for a year.
Route::post('/language', function (Request $request): RedirectResponse {
    $data = $request->validate(['lang' => ['required', Rule::in(SetLanguage::LANGUAGES)]]);

    return back()->cookie(SetLanguage::COOKIE, $data['lang'], 60 * 24 * 365);
})->name('language');

Route::middleware('auth')->group(function () {
    Route::controller(VideoJobController::class)->group(function () {
        Route::get('/dub', 'index')->name('dub.index');
        Route::post('/dub', 'store')->name('dub.store');
        Route::get('/dub/{job}/status', 'status')->name('dub.status');
        Route::get('/dub/{job}/review', 'review')->name('dub.review');
        Route::post('/dub/{job}/review', 'saveReview')->name('dub.review.save');
        Route::get('/dub/{job}/download/{kind}', 'download')->whereIn('kind', ['srt', 'video'])->name('dub.download');
    });

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
