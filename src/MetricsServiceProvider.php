<?php

namespace Npabisz\LaravelMetrics;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Npabisz\LaravelMetrics\Commands\MetricsAggregateHourlyCommand;
use Npabisz\LaravelMetrics\Commands\MetricsAlertCommand;
use Npabisz\LaravelMetrics\Commands\MetricsCleanCommand;
use Npabisz\LaravelMetrics\Commands\MetricsCollectCommand;
use Npabisz\LaravelMetrics\Commands\MetricsStatusCommand;
use Npabisz\LaravelMetrics\Listeners\QueryListener;
use Npabisz\LaravelMetrics\Middleware\RequestMonitor;
use Npabisz\LaravelMetrics\Services\MetricAggregator;
use Npabisz\LaravelMetrics\Services\MetricsService;

class MetricsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/metrics.php', 'metrics');

        $this->app->singleton(MetricsService::class);
        $this->app->singleton(MetricAggregator::class);
    }

    public function boot(): void
    {
        if (!config('metrics.enabled', true)) {
            return;
        }

        $this->registerViews();
        $this->publishAssets();
        $this->registerCommands();
        $this->registerSchedule();
        $this->registerMiddleware();
        $this->registerQueryListener();
        $this->registerRoutes();
        $this->registerDashboard();
        $this->registerGate();
    }

    protected function registerViews(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'metrics');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../resources/views' => resource_path('views/vendor/monitoring'),
            ], 'monitoring-views');
        }
    }

    protected function publishAssets(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/metrics.php' => config_path('metrics.php'),
            ], 'monitoring-config');

            $this->publishes([
                __DIR__ . '/../database/migrations/' => database_path('migrations'),
            ], 'monitoring-migrations');
        }
    }

    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                MetricsCollectCommand::class,
                MetricsCleanCommand::class,
                MetricsStatusCommand::class,
                MetricsAlertCommand::class,
                MetricsAggregateHourlyCommand::class,
            ]);
        }
    }

    protected function registerSchedule(): void
    {
        // Laravel 11+ uses Schedule::class directly from the container
        // Laravel 9/10 also supports this via the booted callback
        $this->app->booted(function () {
            if ($this->app->runningInConsole()) {
                $schedule = $this->app->make(Schedule::class);
                $schedule->command('monitoring:collect')->everyMinute();
                $alertInterval = (int) config('metrics.notifications.interval', 1);
                match (true) {
                    $alertInterval <= 1  => $schedule->command('monitoring:alert')->everyMinute(),
                    $alertInterval <= 5  => $schedule->command('monitoring:alert')->everyFiveMinutes(),
                    $alertInterval <= 10 => $schedule->command('monitoring:alert')->everyTenMinutes(),
                    $alertInterval <= 15 => $schedule->command('monitoring:alert')->everyFifteenMinutes(),
                    $alertInterval <= 30 => $schedule->command('monitoring:alert')->everyThirtyMinutes(),
                    default              => $schedule->command('monitoring:alert')->hourly(),
                };
                $schedule->command('monitoring:aggregate-hourly')->hourly();
                $schedule->command('monitoring:clean')->dailyAt('04:00');
            }
        });
    }

    protected function registerMiddleware(): void
    {
        if (!config('metrics.track_requests', true)) {
            return;
        }

        $group = config('metrics.middleware_group');

        if ($group) {
            // Append to a specific middleware group (works on all Laravel versions)
            $this->app->booted(function () use ($group) {
                /** @var \Illuminate\Routing\Router $router */
                $router = $this->app->make('router');
                $router->pushMiddlewareToGroup($group, RequestMonitor::class);
            });
        } else {
            // Global middleware — approach depends on Laravel version
            $kernelClass = \Illuminate\Contracts\Http\Kernel::class;

            if ($this->app->bound($kernelClass)) {
                // Laravel 9/10: HTTP Kernel exists
                $kernel = $this->app->make($kernelClass);

                if (method_exists($kernel, 'pushMiddleware')) {
                    $kernel->pushMiddleware(RequestMonitor::class);
                }
            } else {
                // Laravel 11+: no HTTP Kernel, use router middleware group
                $this->app->booted(function () {
                    /** @var \Illuminate\Routing\Router $router */
                    $router = $this->app->make('router');
                    $router->pushMiddlewareToGroup('web', RequestMonitor::class);
                    $router->pushMiddlewareToGroup('api', RequestMonitor::class);
                });
            }
        }
    }

    protected function registerQueryListener(): void
    {
        if (!config('metrics.track_queries', true)) {
            return;
        }

        Event::listen(QueryExecuted::class, QueryListener::class);
    }

    protected function registerRoutes(): void
    {
        if (!config('metrics.routes.enabled', false)) {
            return;
        }

        Route::prefix(config('metrics.routes.prefix', 'api/monitoring'))
            ->group(__DIR__ . '/../routes/api.php');
    }

    protected function registerDashboard(): void
    {
        if (!config('metrics.dashboard.enabled', true)) {
            return;
        }

        Route::prefix(config('metrics.dashboard.path', 'metrics'))
            ->middleware('web')
            ->group(__DIR__ . '/../routes/web.php');
    }

    /**
     * Register the monitoring gate (IP-based, like Horizon).
     */
    protected function registerGate(): void
    {
        Gate::define('viewMetrics', function ($user = null) {
            if ($this->app->environment('local')) {
                return true;
            }

            $allowedIPs = config('metrics.allowed_ips');

            if (empty($allowedIPs)) {
                return false;
            }

            $allowedIPs = array_map('trim', explode(',', $allowedIPs));
            $ip = request()->ip();
            $allowed = in_array($ip, $allowedIPs);

            if (!$allowed) {
                Log::channel('single')->info('[Monitoring] Dashboard access denied from ' . $ip);
            }

            return $allowed;
        });
    }
}
