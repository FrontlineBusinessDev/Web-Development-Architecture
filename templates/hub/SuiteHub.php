<?php

// Template: copy to app/Support/SuiteHub.php. Contract: integrations/fbs-suites-hub.md in Web-Development-Architecture.
// Replace every line marked TODO(app); keep send(), sign(), enabled() and quietly() exactly as they are.
//
// config/services.php:
//     'fbs_hub' => [
//         'url' => env('FBS_HUB_URL'),
//         'secret' => env('FBS_HUB_SECRET'),
//         'app' => 'people', // TODO(app): this app's key, hard-coded (people, pay, learn, connect, work, stock)
//     ],

namespace App\Support;

use App\Jobs\SendSuiteHubEvent;
use App\Models\DemoLead; // TODO(app): this app's demo lead model, or remove demoOpened()
use App\Models\Plan;
use App\Models\Tenant; // TODO(app): this app's tenant model (Company, School, Business, Workspace, Store)
use App\Models\User;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

/**
 * Sends events to the FBS Suites hub (POST {FBS_HUB_URL}/hooks/{app}, signed with FBS_HUB_SECRET), which routes them to
 * the FBS CRM. A no-op without the URL or the secret. The generic part (send, sign, quietly) is the same in every FBS app;
 * the tenant part (signedUp, paid, demoOpened, tenant, contact) is this app's.
 */
final class SuiteHub
{
    /**
     * X-FBS-Signature: "sha256=" + HMAC-SHA256(secret, timestamp + "." + raw body). Test vector: secret "test-secret",
     * timestamp 1700000000, body {"a":1} gives sha256=8cb2c3355fca388e9ac2caec004f4d5d7045d74937ab5faad61dc11682247a9f.
     */
    public static function sign(string $secret, int $timestamp, string $body): string
    {
        return 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    private const QUIET = 'suite_hub.quiet';

    public static function enabled(): bool
    {
        return filled(config('services.fbs_hub.url')) && filled(config('services.fbs_hub.secret')) && ! Context::getHidden(self::QUIET);
    }

    /**
     * Runs $callback without sending anything (demo seeding, sandboxes). Hidden Context, so it is per request and job (Octane-safe).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function quietly(callable $callback): mixed
    {
        $previous = Context::getHidden(self::QUIET);
        Context::addHidden(self::QUIET, true);

        try {
            return $callback();
        } finally {
            $previous ? Context::addHidden(self::QUIET, true) : Context::forgetHidden(self::QUIET);
        }
    }

    /**
     * Queues one event after the current transaction commits.
     *
     * @param  array<string, mixed>  $data  merged into the envelope (tenant, plan, signup, payment, demo, ...)
     * @param  array<string, mixed>|null  $contactRef  read by contact() in the job, once the records exist
     */
    public static function send(string $event, array $data, ?array $contactRef = null): void
    {
        if (! self::enabled()) {
            return;
        }

        $payload = [
            'id' => (string) Str::uuid(),
            'event' => $event,
            'version' => 1,
            'occurred_at' => now()->toIso8601String(),
            'app' => (string) config('services.fbs_hub.app'),
            ...$data,
        ];

        SendSuiteHubEvent::dispatch($payload, $contactRef)->afterCommit();
    }

    /**
     * tenant.signed_up. Call it from the tenant model's `created` event so every path is caught (web, Google, staff).
     * Tenants made from the console or seeders are skipped; demo tenants are made inside quietly().
     */
    public static function signedUp(Tenant $tenant): void
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return;
        }

        $user = auth()->user();
        $plan = Plan::find($tenant->plan_id); // TODO(app): how this app finds the tenant's plan

        self::send('tenant.signed_up', [
            'tenant' => self::tenant($tenant),
            'plan' => ['slug' => $plan?->slug, 'name' => $plan?->name, 'trial_ends_at' => $tenant->trial_ends_at?->toIso8601String()],
            // TODO(app): 'google' when the sign-up came through Google
            'signup' => ['method' => $user instanceof User && $user->isPlatformStaff() ? 'staff' : 'web'],
        ], ['tenant_id' => $tenant->id]);
    }

    /**
     * subscription.paid for a settled or approved payment. No console skip: a payment is real wherever it is settled.
     * Call it after the payment is saved; the job is queued after commit, so a hub failure never touches the payment.
     * amount is in major units (pesos, not centavos), currency is 3 letters uppercase, reference is at most 120 characters.
     *
     * @param  array{amount: ?float, currency: string, provider: 'stripe'|'paymongo'|'manual', reference: ?string, paid_at: string}  $payment
     */
    public static function paid(Tenant $tenant, Plan $plan, array $payment): void
    {
        self::send('subscription.paid', [
            'tenant' => self::tenant($tenant),
            'plan' => ['slug' => $plan->slug, 'name' => $plan->name],
            'payment' => $payment,
        ], ['tenant_id' => $tenant->id]);
    }

    /**
     * demo.opened for a live-demo visitor who left their details and ticked the consent box. Send once per lead.
     */
    public static function demoOpened(DemoLead $lead): void
    {
        self::send('demo.opened', [
            // TODO(app): 'type' is this app's tenant type (company, school, business, workspace, store)
            'tenant' => ['ref' => null, 'name' => $lead->company, 'type' => 'company', 'email' => null, 'phone' => null, 'country' => null, 'website' => null],
            'contact' => ['first_name' => $lead->first_name, 'last_name' => $lead->last_name, 'email' => $lead->email, 'phone' => null, 'job_title' => null],
            'demo' => ['visits' => max(1, (int) $lead->visits), 'consented_at' => $lead->consented_at->toIso8601String()],
        ]);
    }

    /** @return array<string, string|null> */
    private static function tenant(Tenant $tenant): array
    {
        return [
            'ref' => (string) $tenant->id,
            'name' => $tenant->name,
            'type' => 'company', // TODO(app): company, school, business, workspace or store
            'email' => null,
            'phone' => null,
            'country' => null,
            'website' => null,
        ];
    }

    /**
     * The contact of an event, read when the job runs: the tenant's first user. Null skips the event (the tenant is
     * gone, or is a demo sandbox).
     *
     * @param  array<string, mixed>  $ref
     * @return array<string, string|null>|null
     */
    public static function contact(array $ref): ?array
    {
        // TODO(app): read across tenants (withoutGlobalScopes) and return null for demo / sandbox tenants
        $tenant = Tenant::query()->withoutGlobalScopes()->find($ref['tenant_id'] ?? 0);
        if ($tenant === null) {
            return null;
        }
        $owner = User::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->orderBy('id')->first();

        return [
            'first_name' => $owner?->first_name,
            'last_name' => $owner?->last_name,
            'email' => $owner?->email,
            'phone' => null,
            'job_title' => $owner ? 'Owner' : null,
        ];
    }
}
