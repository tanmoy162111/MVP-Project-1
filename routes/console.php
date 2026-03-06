<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Routes / Scheduled Tasks
|--------------------------------------------------------------------------
| Phase 1: Skeleton only.
| Actual jobs wired in Phase 5 (invoice overdue) and Phase 7 (reports).
|--------------------------------------------------------------------------
*/

Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Phase 5: Invoice overdue detection
// Schedule::command('invoices:check-overdue')->dailyAt('08:00');

// Phase 7: Daily report aggregation
// Schedule::command('reports:aggregate-daily')->dailyAt('01:00');
