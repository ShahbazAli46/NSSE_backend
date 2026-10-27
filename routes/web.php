<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\BioAttendanceController;

// ---------------------------- ZKTeco ADMS intake ----------------------------
// Machine-facing plain-text protocol; no auth/session of any kind.
// CSRF exclusion for iclock/* lives in bootstrap/app.php (device posts raw
// payloads with no Laravel session, the web group CSRF check would 419 them).
Route::get('/iclock/cdata', [BioAttendanceController::class, 'handshake']);
Route::post('/iclock/cdata', [BioAttendanceController::class, 'store']);
Route::get('/iclock/getrequest', [BioAttendanceController::class, 'ping']);
Route::post('/iclock/cdata/heartbeat', [BioAttendanceController::class, 'ping']);

Route::get('/', function () {
    return view('welcome');
});
