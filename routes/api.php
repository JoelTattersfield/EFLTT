<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\coordinateDetails;

// Difficult to pick a good name as the endpoint does a decent amount, purpose in the hypothetical project might better inform this
Route::post('/coordinates', coordinateDetails::class);
