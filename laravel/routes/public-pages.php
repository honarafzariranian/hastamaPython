<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public content pages, shells, robots/sitemap and static-ish endpoints. Ported from app/main.py.
|--------------------------------------------------------------------------
|
| Registered BEFORE routes/web.php so the SPA fallback never shadows these
| paths.  See bootstrap/app.php.
*/
