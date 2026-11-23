<?php

namespace App\Providers;

use App\Domains\Auth\Listeners\HandleWebSessionOnLogout;
use App\Domains\Auth\WebSessionService;
use App\Domains\Consignment\Dependency\CompositeLrDependencyGuard;
use App\Domains\OwnFleet\Trip\TripLrDependencyGuard;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Subscription\Payments\Gateway\PaymentGateway;
use App\Domains\Subscription\Payments\Gateway\PaymentGatewayAuthorization;
use App\Domains\Subscription\Payments\Gateway\RazorpayClient;
use App\Domains\Subscription\Payments\Gateway\RazorpaySdkClient;
use App\Models\User;
use App\Policies\UserPolicy;
use App\Support\Files\Scanning\MalwareScanner;
use App\Support\Files\Scanning\MalwareScannerFactory;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Razorpay\Api\Api;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(MalwareScannerFactory::class, function ($app): MalwareScannerFactory {
            return new MalwareScannerFactory(isProduction: $app->isProduction());
        });

        $this->app->bind(MalwareScanner::class, function ($app): MalwareScanner {
            return $app->make(MalwareScannerFactory::class)->make();
        });

        $this->app->singleton(PermissionAuthorizer::class);
        $this->app->singleton(WebSessionService::class);
        $this->app->singleton(CompositeLrDependencyGuard::class, function (): CompositeLrDependencyGuard {
            return new CompositeLrDependencyGuard([
                new TripLrDependencyGuard,
            ]);
        });

        $this->app->singleton(PaymentGatewayAuthorization::class);

        // Official Razorpay SDK client (PO-P1-DEPS). Bound only when Test Mode credentials are valid.
        $this->app->bind(RazorpayClient::class, function ($app): RazorpayClient {
            /** @var PaymentGatewayAuthorization $auth */
            $auth = $app->make(PaymentGatewayAuthorization::class);
            $auth->assertGatewayAllowed('razorpay_test');

            return new RazorpaySdkClient(new Api($auth->keyId(), $auth->keySecret()));
        });

        // testing / missing credentials → FakePaymentGateway; non-prod rzp_test_* → SDK gateway.
        // Production Razorpay remains unavailable (PO-P3-PAY-PROD Pending).
        $this->app->bind(PaymentGateway::class, function ($app): PaymentGateway {
            /** @var PaymentGatewayAuthorization $auth */
            $auth = $app->make(PaymentGatewayAuthorization::class);
            $class = $auth->resolveBindingClass();

            return $app->make($class);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureWebSessionListeners();

        Gate::policy(User::class, UserPolicy::class);

        // Super Admin is a protected system privilege, independent of Permission Master.
        // Owner designation remains a company-level designation and is never granted here.
        Gate::before(function (?User $user, string $ability): ?bool {
            if (! $user instanceof User || ! $user->isSuperAdmin()) {
                return null;
            }

            if ($ability === 'actAsCompanyOwner') {
                return null;
            }

            return true;
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    protected function configureWebSessionListeners(): void
    {
        Event::listen(Logout::class, HandleWebSessionOnLogout::class);
    }
}
