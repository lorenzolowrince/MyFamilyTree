<?php

use App\Models\LoginActivity;
use Illuminate\Support\Facades\Schedule;

// Scheduled tasks. On the server one cron entry runs `php artisan schedule:run` every minute.

// Erase accounts whose 14-day deletion grace period has ended.
Schedule::command('accounts:erase-expired')->dailyAt('03:10')->withoutOverlapping();

// Sign-in history is kept for 12 months.
Schedule::command('model:prune', ['--model' => [LoginActivity::class]])->dailyAt('03:20');

// Expired password-reset tokens.
Schedule::command('auth:clear-resets')->dailyAt('03:30');

// Erase photos and documents deleted more than 30 days ago and release their storage.
Schedule::command('media:purge')->dailyAt('03:40')->withoutOverlapping();
