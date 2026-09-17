<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

if (! app()->isProduction()) {
    require __DIR__.'/fake-upstream.php';
}
