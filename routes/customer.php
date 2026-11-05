<?php

use App\Models\Canteen;
use Illuminate\Support\Facades\Route;

Route::get('/', fn (string $canteen) => Canteen::query()->where('slug', $canteen)->where('status', 'active')->exists()
    ? view('ordering::public-catalog', ['canteenSlug' => $canteen])
    : view('customer.home'))
    ->name('home');
