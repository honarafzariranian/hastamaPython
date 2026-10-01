<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Self-registration with admin approval. Ported from app/api/routes/registration.py.
|--------------------------------------------------------------------------
|
| Registered BEFORE routes/web.php so the SPA fallback never shadows these
| paths.  See bootstrap/app.php.
*/
