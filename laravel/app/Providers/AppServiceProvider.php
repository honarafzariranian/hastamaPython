<?php

namespace App\Providers;

use App\Auth\LegacyUserProvider;
use App\Models\User;
use App\Support\Http\LegacyResponseFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Routing\ResponseFactory as ResponseFactoryContract;
use Illuminate\Contracts\View\Factory as ViewFactoryContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\ResponseFactory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
         * The `legacy` user provider.
         *
         * Declared here rather than in a dedicated provider so the one line that
         * changes how authentication works is next to the one line that changes
         * how models behave — the two decisions an engineer reading this project
         * for the first time needs to find together.
         *
         * `Auth::provider()` registers a driver factory on the auth manager, so
         * `config/auth.php` selects it by name (`'driver' => 'legacy'`) and no
         * guard needs to know what class is behind it.  The row class still comes
         * from configuration, so a different model would need no code change here.
         */
        Auth::provider('legacy', function (Application $app, array $config): LegacyUserProvider {
            /** @var class-string<User> $model */
            $model = $config['model'] ?? User::class;

            return new LegacyUserProvider($app->make($model));
        });

        /*
         * JSON goes out unescaped, as Starlette sent it.
         *
         * Registered on the **contract**, which is the key the framework itself uses:
         * `RoutingServiceProvider::registerResponseFactory()` binds
         * `Illuminate\Contracts\Routing\ResponseFactory`, and that is what the
         * `response()` helper resolves (`use Illuminate\Contracts\Routing\ResponseFactory`
         * at the top of `helpers.php`).  Binding the concrete
         * `Illuminate\Routing\ResponseFactory` instead leaves `response()->json()`
         * untouched — the two keys are independent here, since only the contract is
         * bound — which is a failure mode with no symptom at all until someone diffs a
         * response body against the legacy server.
         *
         * The concrete class is pointed at the same instance afterwards so that neither
         * spelling can produce the escaping factory.  See `LegacyResponseFactory` for
         * why the default is not good enough here.
         */
        $this->app->singleton(ResponseFactoryContract::class, static fn (Application $app): LegacyResponseFactory => new LegacyResponseFactory(
            $app[ViewFactoryContract::class],
            $app['redirect'],
        ));

        $this->app->singleton(ResponseFactory::class, static fn (Application $app): ResponseFactoryContract => $app[ResponseFactoryContract::class]);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
         * Fail loudly when a write names an attribute the model will not accept.
         *
         * Laravel's default is to *silently discard* an unfillable attribute, which
         * is the worst possible behaviour for this migration: the legacy tables have
         * 25-column rows, the models open mass assignment through explicit
         * `#[Fillable]` lists, and a column missing from one of those lists would
         * produce a request that reports success while dropping the user's data.  A
         * thrown `MassAssignmentException` in development and in tests is a bug
         * report; a silent no-op is a data-loss incident.
         *
         * Deliberately limited to this one guard.  `preventLazyLoading()` and
         * `preventAccessingMissingAttributes()` are *not* enabled: the legacy tables
         * are addressed by hand-written queries that select partial column sets, and
         * turning those into exceptions would make correct code fail for no security
         * gain.
         */
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
    }
}
