<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => respons()->json(['name' => config('app.name'), 'status' => 'ok']));
