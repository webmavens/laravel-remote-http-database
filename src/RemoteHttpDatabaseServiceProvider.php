<?php

namespace Laravel\RemoteHttpDatabase;

use Illuminate\Database\Connection;
use Illuminate\Support\ServiceProvider;

class RemoteHttpDatabaseServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
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
    public function boot()
    {
        // Publish configuration file
        $this->publishes([
            __DIR__.'/../config/remote-http-database.php' => config_path('remote-http-database.php'),
        ], 'remote-http-config');

        // Publish remote endpoint file
        $this->publishes([
            __DIR__.'/../resources/endpoint/remote-db-endpoint.php' => base_path('remote-db-endpoint.php'),
        ], 'remote-http-endpoint');

        // Merge default configuration
        $this->mergeConfigFrom(
            __DIR__.'/../config/remote-http-database.php',
            'remote-http-database'
        );
    }
}
