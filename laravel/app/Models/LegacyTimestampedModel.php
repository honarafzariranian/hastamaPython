<?php

namespace App\Models;

/**
 * Base model for the legacy tables that own `created_at` / `updated_at`.
 *
 * These are the tables the Python application writes with an explicit
 * `updated_at = SYSUTCDATETIME()` on every mutation (`notifications`,
 * `user_notifications`, `tickets`, `password_reset_requests`, …).  Letting
 * Eloquent maintain the pair keeps that behaviour without asking twenty services
 * to remember it, and the values are equivalent: the application timezone is UTC
 * (Laravel 13 hard-codes `config('app.timezone')` to `UTC` and the database
 * stores UTC), so `now()` and `SYSUTCDATETIME()` agree.
 *
 * A model whose table has only one of the two columns sets the other to null:
 *
 *     public const CREATED_AT = null;   // table has updated_at only
 *     public const UPDATED_AT = null;   // table has created_at only
 *
 * Both are honoured — `HasTimestamps` skips a null column name rather than
 * writing to it.
 */
abstract class LegacyTimestampedModel extends LegacyModel
{
    /**
     * @var bool
     */
    public $timestamps = true;
}
