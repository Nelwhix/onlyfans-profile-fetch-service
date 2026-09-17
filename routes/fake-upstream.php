<?php

use App\Domain\Profile\Support\SimulatesUpstreamProfileResponses;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Fake Upstream Routes
|--------------------------------------------------------------------------
|
| Stands in for onlyfans.com for the local workload demo (see the
| profiles:demo-workload command). Never registered in production - see
| the guard in routes/web.php.
|
*/

Route::get('/api/profiles/{username}', function (string $username, SimulatesUpstreamProfileResponses $simulator) {
    return $simulator->respond($username);
});
