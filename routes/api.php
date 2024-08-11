<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;

Route::group(['prefix' => 'auth'], function () {
    
    Route::post('login', [AuthController::class, 'login']);
    Route::post('register', [AuthController::class, 'register']);

    Route::group(['middleware' => 'auth:sanctum'], function() {
      Route::get('logout', [AuthController::class, 'logout']);
      Route::get('user', [AuthController::class, 'user']);
    });
});

/*
Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
*/
/*
use App\Http\Controllers\AuthController;
   
Route::controller(AuthController::class)->group(function(){
    Route::post('register', 'register');
    Route::post('login', 'login');
});


Route::group([
    "middleware" => ["auth:sanctum"]
],function(){
    Route::post("profile",[AuthController::class,"profile"]);
    Route::post("logout",[AuthController::class,"logout"]);
});

/*
use App\Http\Controllers\ProductController;
Route::middleware('auth:sanctum')->group( function () {
    Route::resource('products', ProductController::class);
});
*/