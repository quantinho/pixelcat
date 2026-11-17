<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('pixelforge:dispatch')->everyMinute()->onOneServer()->withoutOverlapping();
Schedule::command('pixelforge:poll')->everyMinute()->onOneServer()->withoutOverlapping();
Schedule::command('pixelforge:reconcile')->dailyAt('00:15')->timezone('UTC')->onOneServer()->withoutOverlapping();
