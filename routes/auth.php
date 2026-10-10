<?php

use App\Http\Controllers\Compte\DeconnexionController;
use Illuminate\Support\Facades\Route;

// Anciennes adresses du kit Breeze : redirections permanentes vers les parcours Raaga
// (connexion et inscription par téléphone + code SMS, profil dans Mon espace).
Route::permanentRedirect('/login', '/connexion')->name('login');
Route::permanentRedirect('/register', '/inscription')->name('register');
Route::permanentRedirect('/profile', '/espace/profil');

Route::post('/logout', DeconnexionController::class)->middleware('auth')->name('logout');
