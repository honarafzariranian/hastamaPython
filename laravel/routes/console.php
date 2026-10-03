<?php

use App\Support\Notifications\NotificationService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
|
| `publish_due_notifications` in `app/services/background_tasks.py` ran on a
| 1-second APScheduler interval, publishing every scheduled notification whose
| time had come.  The Laravel scheduler's finest granularity is one second, so
| the interval is preserved exactly.
|
| The inline sweep the Python also ran inside `admin_list`, `user_list` and
| `unread_count` is already ported — it lives in `NotificationService` and runs
| with those reads.  This entry is the standalone timer the APScheduler job was.
|
| On Windows this is executed by the Task Scheduler entry that runs
| `php artisan schedule:work` (see DEPLOYMENT.md); `schedule:run` is the
| single-shot alternative.
|
*/
app(Schedule::class)->call(fn (NotificationService $service) => $service->publishDue())->everySecond();
