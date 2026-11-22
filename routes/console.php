<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('inbox:sync')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('inbox:import-history')->everyTenMinutes()->withoutOverlapping();
Schedule::command('inbox:apply-retention')->hourly()->withoutOverlapping();
Schedule::command('inbox:purge-disconnected-accounts')->hourly()->withoutOverlapping();
