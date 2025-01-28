<?php

namespace October\Rain\Database;

use Faker\Factory as FakerFactory;
use Faker\Generator as FakerGenerator;
use Illuminate\Contracts\Queue\EntityResolver;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\QueueEntityResolver;
use Illuminate\Database\DatabaseTransactionsManager;
use October\Rain\Database\Schema\Blueprint;
use Illuminate\Database\DatabaseServiceProvider as DatabaseServiceProviderBase;
use Illuminate\Database\DatabaseManager;

class DatabaseServiceProvider extends DatabaseServiceProviderBase
{
    /**
     * The array of resolved Faker instances.
     *
     * @var array
     */
    protected static $fakers = [];

    /**
     * Bootstrap the application events.
     *
     * @return void
     */
    public function boot()
    {
        Model::setConnectionResolver($this->app['db']);

        Model::setEventDispatcher($this->app['events']);

        $this->swapSchemaBuilderBlueprint();
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        Model::clearBootedModels();

        $this->registerConnectionServices();
        $this->registerEloquentFactory();
        $this->registerQueueableEntityResolver();

        // The connection factory is used to create the actual connection instances on
        // the database. We will inject the factory into the manager so that it may
        // make the connections while they are actually needed and not of before.
        $this->app->singleton('db.factory', function ($app) {
            return new ConnectionFactory($app);
        });

        // The database manager is used to resolve various connections, since multiple
        // connections might be managed. It also implements the connection resolver
        // interface which may be used by other components requiring connections.
        $this->app->singleton('db', function ($app) {
            return new DatabaseManager($app, $app['db.factory']);
        });

        $this->app->bind('db.connection', function ($app) {
            return $app['db']->connection();
        });

        $this->app->singleton('db.dongle', function ($app) {
            return new Dongle($this->getDefaultDatabaseDriver(), $app['db']);
        });

        // Disable memory cache when running in console environment. This should
        // prevent daemon processes from handling stale data in memory, however
        // it should be kept active for the purpose of accurate unit testing.
        if ($this->app->runningInConsole() && !$this->app->runningUnitTests()) {
            MemoryCache::instance()->enabled(false);
        }
    }

    /**
     * Register the primary database bindings.
     *
     * @return void
     */
    protected function registerConnectionServices()
    {
        // The connection factory is used to create the actual connection instances on
        // the database. We will inject the factory into the manager so that it may
        // make the connections while they are actually needed and not of before.
        $this->app->singleton('db.factory', function ($app) {
            return new ConnectionFactory($app);
        });

        // The database manager is used to resolve various connections, since multiple
        // connections might be managed. It also implements the connection resolver
        // interface which may be used by other components requiring connections.
        $this->app->singleton('db', function ($app) {
            return new DatabaseManager($app, $app['db.factory']);
        });

        $this->app->bind('db.connection', function ($app) {
            return $app['db']->connection();
        });

        $this->app->bind('db.schema', function ($app) {
            return $app['db']->connection()->getSchemaBuilder();
        });

        $this->app->singleton('db.transactions', function ($app) {
            return new DatabaseTransactionsManager;
        });
    }

    /**
     * Register the Eloquent factory instance in the container.
     *
     * @return void
     */
    protected function registerEloquentFactory()
    {
        $this->app->singleton(FakerGenerator::class, function ($app, $parameters) {
            $locale = $parameters['locale'] ?? $app['config']->get('app.faker_locale', 'en_US');

            if (! isset(static::$fakers[$locale])) {
                static::$fakers[$locale] = FakerFactory::create($locale);
            }

            static::$fakers[$locale]->unique(true);

            return static::$fakers[$locale];
        });
    }

    /**
     * Register the queueable entity resolver implementation.
     *
     * @return void
     */
    protected function registerQueueableEntityResolver()
    {
        $this->app->singleton(EntityResolver::class, function () {
            return new QueueEntityResolver;
        });
    }

    /**
     * Returns the default database driver, not just the connection name.
     * @return string
     */
    protected function getDefaultDatabaseDriver()
    {
        $defaultConnection = $this->app['db']->getDefaultConnection();
        return $this->app['config']['database.connections.' . $defaultConnection . '.driver'];
    }

    /**
     * Adds a touch of Rain to the Schema Blueprints.
     * @return void
     */
    protected function swapSchemaBuilderBlueprint()
    {
        $this->app['events']->listen('db.schema.getBuilder', function (\Illuminate\Database\Schema\Builder $builder) {
            $builder->blueprintResolver(function ($table, $callback) {
                return new Blueprint($table, $callback);
            });
        });
    }
}
