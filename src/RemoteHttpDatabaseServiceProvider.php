<?php

namespace Laravel\RemoteHttpDatabase;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\RemoteHttpDatabase\Http\Controllers\RemoteDatabaseEndpointController;

class RemoteHttpDatabaseServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register(): void
    {
        // Register the connector
        $this->app->bind('db.connector.remote-http', function () {
            return new Connectors\RemoteHttpConnector;
        });

        // Register the connection resolver
        Connection::resolverFor('remote-http', function ($connection, $database, $prefix, $config) {
            return new RemoteHttpConnection($connection, $database, $prefix, $config);
        });
    }

    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot(): void
    {
        // Publish configuration file
        $this->publishes([
            __DIR__.'/../config/remote-http-database.php' => config_path('remote-http-database.php'),
        ], 'remote-http-config');

        // Publish remote endpoint file (for backward compatibility)
        $this->publishes([
            __DIR__.'/../resources/endpoint/remote-db-endpoint.php' => base_path('remote-db-endpoint.php'),
        ], 'remote-http-endpoint');

        // Merge default configuration
        $this->mergeConfigFrom(
            __DIR__.'/../config/remote-http-database.php',
            'remote-http-database'
        );

        // Register the endpoint route if endpoint configuration is provided
        // Avoid using env() directly for cached config compatibility
        $endpointApiKey = config('remote-http-database.endpoint_api_key');
        $endpointPath = config('remote-http-database.endpoint_path', '/remote-db-endpoint');

        if (! empty($endpointApiKey)) {
            $this->registerEndpointRoute($endpointPath);
        }
    }

    /**
     * Register the endpoint route with appropriate middleware.
     *
     * @param  string  $path
     * @return void
     */
    protected function registerEndpointRoute(string $path): void
    {
        // Get configured middleware or use sensible defaults
        $middleware = config('remote-http-database.endpoint_middleware', []);

        // If no middleware configured, use API-appropriate defaults
        if (empty($middleware)) {
            $middleware = $this->getDefaultMiddleware();
        }

        Route::match(['GET', 'POST'], $path, RemoteDatabaseEndpointController::class)
            ->middleware($middleware)
            ->name('remote-db-endpoint');
    }

    /**
     * Get default middleware for the endpoint.
     *
     * Uses 'api' middleware group if available, otherwise applies
     * throttle middleware directly to prevent brute-force attacks.
     *
     * @return array
     */
    protected function getDefaultMiddleware(): array
    {
        // Check if 'api' middleware group exists (Laravel 8+)
        $router = $this->app->make('router');
        $middlewareGroups = $router->getMiddlewareGroups();

        if (isset($middlewareGroups['api'])) {
            return ['api'];
        }

        // Fallback: apply throttle directly if available
        $routeMiddleware = $router->getMiddleware();

        if (isset($routeMiddleware['throttle'])) {
            // Apply rate limiting: 60 requests per minute
            return ['throttle:60,1'];
        }

        // No middleware available - endpoint will work but without rate limiting
        return [];
    }
}
