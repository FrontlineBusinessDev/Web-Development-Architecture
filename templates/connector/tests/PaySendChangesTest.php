<?php

use App\FbsSync\ApplyChanges;
use App\Jobs\PushFbsChanges;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\FbsChange;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

// Pay's own edits to shared fields reach the linked apps (fbs-suites-mcp plan 2026-11-18-every-app-two-way-sync; contract fbs-record.v1).

beforeEach(function () {
    fakeFbsId();
    Queue::fake(); // each test runs the push itself
    $this->company = Company::factory()->create();
    $this->employee = Employee::factory()->create(['company_id' => $this->company->id, 'branch_id' => Branch::factory()->create(['company_id' => $this->company->id])->id,
        'personal' => ['firstName' => 'Ana', 'lastName' => 'Cruz', 'contactNumber' => '0917'],
        'employment' => ['department' => 'Sales', 'position' => 'Clerk', 'status' => 'active', 'dateHired' => '2025-01-06', 'employmentType' => 'regular', 'category' => 'regular']]);
    $this->employee->forceFill(['external_id' => 'people:5:10'])->save();
    FbsChange::withoutGlobalScopes()->delete();

    $this->peers = [['app' => 'people', 'tenant' => '5', 'base_url' => 'https://people.fbs.test', 'types' => ['person', 'time']],
        ['app' => 'work', 'tenant' => '3', 'base_url' => 'https://work.fbs.test', 'types' => ['person', 'time']]];
    $this->sent = [];
    test()->hub = function (Request $r) {
        return match (true) {
            str_contains($r->url(), '/api/fbs-id/connector-peers') => Http::response(['success' => true, 'data' => ['peers' => $this->peers]]),
            str_ends_with($r->url(), '/api/fbs-id/connector-token') => Http::response(['success' => true, 'data' => ['token' => "tok-{$r['to_app']}", 'expires_in' => 300]]),
            str_ends_with($r->url(), '/api/fbs/connector/changes') => (function () use ($r) {
                $this->sent[] = [parse_url($r->url(), PHP_URL_HOST), $r->header('Authorization')[0] ?? null, $r['changes']];

                return Http::response(['success' => true, 'data' => ['answers' => collect($r['changes'])->mapWithKeys(fn ($c) => [$c['change_id'] => 'accepted'])]]);
            })(),
            default => null,
        };
    };
});

function editInPay(array $personal = [], array $employment = []): void
{
    $e = test()->employee->fresh();
    $e->forceFill(['personal' => [...$e->personal, ...$personal], 'employment' => [...$e->employment, ...$employment]])->save();
}

test('an edit in Pay records only the shared fields that changed', function () {
    editInPay(['contactNumber' => '0999'], ['department' => 'Finance']);

    $change = FbsChange::withoutGlobalScopes()->sole();
    expect($change->only(['type', 'external_id', 'received_from', 'status', 'origin_app']))
        ->toBe(['type' => 'person', 'external_id' => 'people:5:10', 'received_from' => null, 'status' => 'pending', 'origin_app' => 'people'])
        ->and($change->fields)->toBe(['mobile' => '0999', 'department' => 'Finance']);
    Queue::assertPushed(PushFbsChanges::class, fn ($job) => $job->companyId === $this->company->id);
});

test('an edit of Pay-only fields records nothing, and a received change is never recorded', function () {
    $e = $this->employee->fresh();
    $e->forceFill(['bank' => ['accountNumber' => '123']])->save();
    ApplyChanges::receiving(fn () => editInPay(['contactNumber' => '0911']));

    expect(FbsChange::withoutGlobalScopes()->count())->toBe(0);
});

test('a person Pay made gets its own external id', function () {
    $this->employee->forceFill(['external_id' => null])->saveQuietly();
    editInPay(['contactNumber' => '0999']);

    expect($this->employee->fresh()->external_id)->toBe("pay:{$this->company->id}:person:{$this->employee->id}")
        ->and(FbsChange::withoutGlobalScopes()->sole()->external_id)->toBe("pay:{$this->company->id}:person:{$this->employee->id}");
});

test('the push sends person changes to the center app only, with a hub token', function () {
    editInPay(['contactNumber' => '0999']);

    (new PushFbsChanges($this->company->id))->handle();

    expect($this->sent)->toHaveCount(1)
        ->and($this->sent[0][0])->toBe('people.fbs.test')
        ->and($this->sent[0][1])->toBe('Bearer tok-people')
        ->and($this->sent[0][2][0]['fields'])->toBe(['mobile' => '0999'])
        ->and($this->sent[0][2][0]['changed_by']['app'])->toBe('pay')
        ->and(FbsChange::withoutGlobalScopes()->sole()->status)->toBe('accepted');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/fbs-id/connector-token') && $r['scope'] === 'connector.person.write'
        && $r['from_tenant'] === (string) $this->company->id && $r->hasHeader('Authorization', 'Basic '.base64_encode('pay-client:pay-secret')));
});

test('without the center app, the push sends to every linked app', function () {
    $this->peers = array_values(array_filter($this->peers, fn ($p) => $p['app'] !== 'people'));
    $this->peers[] = ['app' => 'stock', 'tenant' => '2', 'base_url' => 'https://stock.fbs.test', 'types' => ['person']];
    editInPay(['contactNumber' => '0999']);

    (new PushFbsChanges($this->company->id))->handle();

    expect(collect($this->sent)->pluck(0)->sort()->values()->all())->toBe(['stock.fbs.test', 'work.fbs.test']);
});

test('the outbox keeps a change a peer did not take, and the next push sends it', function () {
    editInPay(['contactNumber' => '0999']);
    $real = test()->hub;
    test()->hub = fn (Request $r) => str_ends_with($r->url(), '/api/fbs/connector/changes') ? Http::response('', 503) : $real($r);

    (new PushFbsChanges($this->company->id))->handle();
    expect(FbsChange::withoutGlobalScopes()->sole()->status)->toBe('pending');

    test()->hub = $real;
    (new PushFbsChanges($this->company->id))->handle();
    expect(FbsChange::withoutGlobalScopes()->sole()->status)->toBe('accepted')->and($this->sent)->toHaveCount(1);
});

test('a change stays pending while FBS Suites does not answer, and is marked none when no linked app takes it', function () {
    editInPay(['contactNumber' => '0999']);
    $real = test()->hub;
    test()->hub = fn (Request $r) => str_contains($r->url(), '/connector-peers') ? Http::response('', 503) : $real($r);
    (new PushFbsChanges($this->company->id))->handle();
    expect(FbsChange::withoutGlobalScopes()->sole()->status)->toBe('pending');

    test()->hub = $real;
    $this->peers = [];
    (new PushFbsChanges($this->company->id))->handle();
    expect(FbsChange::withoutGlobalScopes()->sole()->status)->toBe('none');
});

test('nothing is logged while FBS ID is off', function () {
    config(['services.fbs_id.client_secret' => null]);
    editInPay(['contactNumber' => '0999']);

    expect(FbsChange::withoutGlobalScopes()->count())->toBe(0);
});
