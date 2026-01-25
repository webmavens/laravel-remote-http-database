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
        $endpointApiKey = config('remote-http-database.endpoint_api_key') ?? env('REMOTE_DB_API_KEY');
        $endpointPath = config('remote-http-database.endpoint_path', '/remote-db-endpoint');

        if (! empty($endpointApiKey)) {
            Route::match(['GET', 'POST'], $endpointPath, RemoteDatabaseEndpointController::class)
                ->name('remote-db-endpoint');
        }
    }
}
