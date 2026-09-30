<?php

use App\Http\Controllers\BookingController;
use App\Http\Controllers\CheckRateController;
use App\Http\Controllers\ContentHotelController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeveloperReferenceController;
use App\Http\Controllers\HbxBookingListController;
use App\Http\Controllers\HbxLogController;
use App\Http\Controllers\HbxStatusController;
use App\Http\Controllers\HotelSearchController;
use App\Http\Controllers\RateSelectionController;
use Illuminate\Support\Facades\Route;

Route::get('/', DashboardController::class)->name('dashboard');

Route::get('/hotels/search', [HotelSearchController::class, 'create'])->name('hotels.search');
Route::post('/hotels/search', [HotelSearchController::class, 'store'])->name('hotels.search.store');
Route::get('/hotels/search/{search}', [HotelSearchController::class, 'show'])->name('hotels.results');
Route::get('/hotels/search/{search}/hotels/{hotelCode}/rooms', [HotelSearchController::class, 'rooms'])->name('hotels.rooms');
Route::get('/developer/hbx/searches/{search}/raw', [HotelSearchController::class, 'raw'])->name('developer.search-raw');
Route::post('/hotels/search/{search}/check-rate', [CheckRateController::class, 'store'])->name('hotels.check-rate');
Route::post('/hotels/search/{search}/select', [RateSelectionController::class, 'store'])->name('hotels.select');

Route::get('/bookings', [BookingController::class, 'index'])->name('bookings.index');
Route::get('/bookings/hbx', HbxBookingListController::class)->name('bookings.hbx');
Route::get('/bookings/create', [BookingController::class, 'create'])->name('bookings.create');
Route::post('/bookings', [BookingController::class, 'store'])->name('bookings.store');
Route::get('/bookings/{booking}', [BookingController::class, 'show'])->name('bookings.show');
Route::post('/bookings/{booking}/refresh', [BookingController::class, 'refresh'])->name('bookings.refresh');
Route::post('/bookings/{booking}/cancel-simulation', [BookingController::class, 'simulateCancellation'])->name('bookings.cancel-simulation');
Route::post('/bookings/{booking}/cancel', [BookingController::class, 'cancel'])->name('bookings.cancel');
Route::post('/bookings/{booking}/modify-simulation', [BookingController::class, 'simulateModification'])->name('bookings.modify-simulation');
Route::post('/bookings/{booking}/modify', [BookingController::class, 'modify'])->name('bookings.modify');

Route::redirect('/content', '/content/hotels')->name('content.index');
Route::get('/content/hotels', [ContentHotelController::class, 'index'])->name('content.hotels.index');
Route::get('/content/hotels/{hotelCode}', [ContentHotelController::class, 'show'])->whereNumber('hotelCode')->name('content.hotels.show');
Route::get('/developer/content/hotels/{hotelCode}/snapshot', [ContentHotelController::class, 'snapshot'])->whereNumber('hotelCode')->name('developer.content.snapshot');

Route::get('/hbx/status', [HbxStatusController::class, 'show'])->name('hbx.status');
Route::post('/hbx/status', [HbxStatusController::class, 'test'])->name('hbx.status.test');

Route::get('/developer/hbx/logs', HbxLogController::class)->name('developer.logs');
Route::get('/developer/reference', DeveloperReferenceController::class)->name('developer.reference');
