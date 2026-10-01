<?php

use App\Http\Controllers\Automation\AutomationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Internal automation conversations. Ported from app/api/routes/automation.py.
|--------------------------------------------------------------------------
|
| Registered BEFORE routes/web.php so the SPA fallback never shadows these
| paths.  See bootstrap/app.php.
*/

Route::prefix('api/automation')->middleware('legacy.session:optional')->group(function (): void {
    /*
     | Literals first: FastAPI dispatches a static path ahead of any `{param}`
     | sibling, and both `/reopen-request` (post) and `/attachments` (post)
     | have parameterised neighbours.  Every name is prefixed `automation.`
     | because route names are globally unique.
     */
    Route::get('', [AutomationController::class, 'index'])->name('automation.index');
    Route::post('', [AutomationController::class, 'create'])->name('automation.create');

    Route::post('/{conversationId}/complete', [AutomationController::class, 'complete'])
        ->name('automation.complete');

    Route::post('/{conversationId}/reopen-request', [AutomationController::class, 'requestReopen'])
        ->name('automation.reopen-request');
    Route::post('/{conversationId}/reopen-request/{requestId}/approve', [AutomationController::class, 'approveReopen'])
        ->name('automation.approve-reopen');

    Route::post('/{conversationId}/messages', [AutomationController::class, 'addMessage'])
        ->name('automation.messages');

    Route::post('/{conversationId}/attachments', [AutomationController::class, 'uploadAttachment'])
        ->name('automation.attachments');
    Route::get('/{conversationId}/attachments/{attachmentId}', [AutomationController::class, 'downloadAttachment'])
        ->name('automation.attachments.download');

    Route::get('/{conversationId}', [AutomationController::class, 'show'])
        ->name('automation.show');
    Route::delete('/{conversationId}', [AutomationController::class, 'destroy'])
        ->name('automation.destroy');
});
