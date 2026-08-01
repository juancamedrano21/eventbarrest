<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// La pantalla del POS: cascaron publico, el estado vive en el dispositivo
// y toda la autorizacion en la API por token.
Route::view('/pos', 'pos');
