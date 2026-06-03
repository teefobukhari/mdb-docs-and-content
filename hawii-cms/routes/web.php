<?php

use App\Http\Controllers\PublicController;
use Illuminate\Support\Facades\Route;

Route::get('/', [PublicController::class, 'home'])->name('home');
Route::get('/about', [PublicController::class, 'about'])->name('about');

Route::get('/services', [PublicController::class, 'services'])->name('services.index');
Route::get('/services/{service:slug}', [PublicController::class, 'serviceShow'])->name('services.show');

Route::get('/industries', [PublicController::class, 'industries'])->name('industries.index');

Route::get('/case-studies', [PublicController::class, 'caseStudies'])->name('cases.index');
Route::get('/case-studies/{caseStudy:slug}', [PublicController::class, 'caseStudyShow'])->name('cases.show');

Route::get('/blog', [PublicController::class, 'blog'])->name('blog.index');
Route::get('/blog/{post:slug}', [PublicController::class, 'postShow'])->name('blog.show');

Route::get('/contact', [PublicController::class, 'contact'])->name('contact');
Route::post('/contact', [PublicController::class, 'contactStore'])->name('contact.store');

// Standalone pages (privacy, terms, …)
Route::get('/p/{page:slug}', [PublicController::class, 'page'])->name('page.show');
