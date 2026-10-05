<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('monitors:dispatch')->everyMinute()->withoutOverlapping(2)->onOneServer();
Schedule::command('notifications:dispatch')->everyMinute()->withoutOverlapping(2)->onOneServer();
Schedule::command('sentinel:prune')->hourly()->withoutOverlapping(30)->onOneServer();
Schedule::command('sanctum:prune-expired --hours=24')->daily()->onOneServer();
