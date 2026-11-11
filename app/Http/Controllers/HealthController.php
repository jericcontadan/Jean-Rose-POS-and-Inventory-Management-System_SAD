<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

// GET /health: public check used by deploy.sh and monitoring.
// It does not touch the database, so it only proves PHP and Laravel are running.
class HealthController extends Controller
{
    public function __invoke(): Response
    {
        return response('ok', 200)->header('Content-Type', 'text/plain');
    }
}
