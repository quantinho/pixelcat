<?php

use Illuminate\Support\Facades\Schedule;

// Follow-ups are event-driven delayed jobs (spec FR-011); these hourly runs are only the
// safety net, so an idle app lets compute and Serverless Postgres scale to zero.
Schedule::command('pixelforge:dispatch')->hourly()->onOneServer()->withoutOverlapping();
Schedule::command('pixelforge:poll')->hourlyAt(30)->onOneServer()->withoutOverlapping();
Schedule::command('pixelforge:reconcile')->dailyAt('00:15')->timezone('UTC')->onOneServer()->withoutOverlapping();
