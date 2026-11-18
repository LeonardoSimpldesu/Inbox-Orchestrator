<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('inbox:sync')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('inbox:import-history')->everyTenMinutes()->withoutOverlapping();
