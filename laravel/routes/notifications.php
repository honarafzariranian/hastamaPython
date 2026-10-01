<?php

use App\Http\Controllers\Notifications\AdminNotificationController;
use App\Http\Controllers\Notifications\UserNotificationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Notification centre - admin CRUD, publish schedule, per-user read state, SSE streams. Ported from app/api/routes/notifications.py.
|--------------------------------------------------------------------------
|
| Registered BEFORE routes/web.php so the SPA fallback never shadows these
| paths.  See bootstrap/app.php.
|
| These are browser-session endpoints, not Laravel's `api` group: the `/api`
| prefix is a naming artifact of the single-process Python application, and
| every handler reads the session cookie.  They live here for the same reason
| the call-system's `/api/*` routes do.
|
| Two decisions are deliberate rather than incidental:
|
| * **The guard is in the handler, not in middleware.**  Every Python handler
|   calls `_actor(request)` or `_actor(request, admin=True)` as its first line,
|   and those raise FastAPI's `{"detail": …}` bodies — which are not the bodies
|   `App\Http\Middleware\EnsureAdmin` produces.  `NotificationActor` reproduces
|   them, so no `admin` middleware is applied and the two refusal bodies cannot
|   drift.  The per-row handlers (`POST /api/notifications/{id}/read` and
|   friends) get no middleware at all: the inventory calls them "none (public)",
|   and they are — the handler's own `_actor` answers the anonymous request.
|
| * **`legacy.session:optional` is on every route.**  The Python's
|   `_SessionRegistryMiddleware` ran app-wide and passed an anonymous request
|   through to the handler's own guard; the `:optional` parameter reproduces
|   exactly that, so a revoked session is still refused *after* the guard has
|   resolved the user.
|
| Registration order matters twice, and both times it is the Python's order:
| the literal segments are declared before the `{notification_id}` wildcard that
| would otherwise swallow them, and `DELETE /admin/notifications/{notification_id}`
| is declared **before** `delete-all` — which is what makes `delete-all`
| unreachable (422 `int_parsing`) on the running server too.
|
*/

Route::prefix('api')->middleware(['legacy.session:optional'])->group(function (): void {

    /*
    |--------------------------------------------------------------------------
    | Administrator surface
    |--------------------------------------------------------------------------
    */

    Route::get('/admin/notification-targets', [AdminNotificationController::class, 'targetOptions'])
        ->name('notifications.admin.targets');

    Route::get('/admin/notifications', [AdminNotificationController::class, 'index'])
        ->name('notifications.admin.index');

    // Literals first: `read-all` would otherwise be swallowed by `{notification_id}`.
    Route::post('/admin/notifications/read-all', [AdminNotificationController::class, 'markAllRead'])
        ->name('notifications.admin.read-all');

    Route::post('/admin/notifications', [AdminNotificationController::class, 'store'])
        ->name('notifications.admin.store');

    Route::put('/admin/notifications/{notification_id}', [AdminNotificationController::class, 'update'])
        ->name('notifications.admin.update');

    Route::post('/admin/notifications/{notification_id}/publish', [AdminNotificationController::class, 'publish'])
        ->name('notifications.admin.publish');

    Route::post('/admin/notifications/{notification_id}/read', [AdminNotificationController::class, 'markRead'])
        ->name('notifications.admin.read');

    Route::post('/admin/notifications/{notification_id}/unread', [AdminNotificationController::class, 'markUnread'])
        ->name('notifications.admin.unread');

    // Before `delete-all`: the Python's registration order makes `delete-all`
    // unreachable, and the port reproduces that (see the controller docblock).
    Route::delete('/admin/notifications/{notification_id}', [AdminNotificationController::class, 'destroy'])
        ->name('notifications.admin.delete');

    Route::post('/admin/notifications/{notification_id}/archive', [AdminNotificationController::class, 'archive'])
        ->name('notifications.admin.archive');

    Route::delete('/admin/notifications/delete-all', [AdminNotificationController::class, 'deleteAll'])
        ->name('notifications.admin.delete-all');

    /*
    |--------------------------------------------------------------------------
    | User surface
    |--------------------------------------------------------------------------
    */

    // Every literal before the `{notification_id}` wildcard.
    Route::get('/notifications', [UserNotificationController::class, 'index'])
        ->name('notifications.user.index');

    Route::get('/notifications/unread-count', [UserNotificationController::class, 'unreadCount'])
        ->name('notifications.user.unread-count');

    Route::get('/notifications/poll', [UserNotificationController::class, 'poll'])
        ->name('notifications.user.poll');

    Route::get('/notifications/stream', [UserNotificationController::class, 'stream'])
        ->name('notifications.user.stream');

    Route::get('/notifications/admin-stream', [UserNotificationController::class, 'adminStream'])
        ->name('notifications.user.admin-stream');

    Route::post('/notifications/read-all', [UserNotificationController::class, 'markAllRead'])
        ->name('notifications.user.read-all');

    Route::get('/notifications/{notification_id}', [UserNotificationController::class, 'show'])
        ->name('notifications.user.show');

    Route::post('/notifications/{notification_id}/read', [UserNotificationController::class, 'markRead'])
        ->name('notifications.user.read');

    Route::post('/notifications/{notification_id}/unread', [UserNotificationController::class, 'markUnread'])
        ->name('notifications.user.unread');

    Route::delete('/notifications/{notification_id}', [UserNotificationController::class, 'dismiss'])
        ->name('notifications.user.dismiss');
});
