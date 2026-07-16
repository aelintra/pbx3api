<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

/**
 *  Added for Sanctum abilities
 */
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        //
/**
 *  Added for Sanctum 
 */
        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
            'fleet.token' => \App\Http\Middleware\AuthenticateFleetServiceToken::class,
        ]);

    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })
    ->withSchedule(function (Schedule $schedule) {
        $schedule->command('pbx3:backup-run --trigger=scheduled')
            ->dailyAt('02:00')
            ->withoutOverlapping(120);

        $schedule->command('pbx3:recordings-offload')
            ->everyTenMinutes()
            ->withoutOverlapping(30);

        $schedule->command('pbx3:recordings-s3-upload')
            ->everyTenMinutes()
            ->withoutOverlapping(30);

        $schedule->command('pbx3:recordings-retain')
            ->dailyAt('02:30')
            ->withoutOverlapping(60);

        $schedule->command('pbx3:recordings-reconcile')
            ->dailyAt('03:15')
            ->withoutOverlapping(90);

        $schedule->command('pbx3:ops-register-loops')
            ->everyMinute()
            ->withoutOverlapping(2);
    })
    ->create();
