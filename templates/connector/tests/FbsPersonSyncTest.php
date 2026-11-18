<?php

use App\FbsSync\ApplyChanges;
use App\Jobs\PushFbsChanges;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\FbsChange;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

// FBS People is the center for `person` (fbs-suites-mcp plan 2026-11-18-every-app-two-way-sync; contract fbs-record.v1): it takes a
// change from any linked app, passes it on to the others, and sends its own edits to all of them.

beforeEach(function () {
    fakeFbsId();
    Queue::fake(); // each test runs the push itself
    [$this->company] = companyWithOwner('growth');
    $this->employee = Company::run($this->company, function () {
        Department::create(['name' => 'Finance']);
        $e = Employee::create(['employee_number' => 'E-1', 'first_name' => 'Ana', 'last_name' => 'Cruz', 'mobile' => '0917', 'employment_type' => 'regular', 'status' => 'active', 'hired_on' => '2025-01-06']);
        $e->forceFill(['learn_external_id' => 'learn:4:faculty:9'])->save();

        return $e;
    });
    $this->id = "people:{$this->company->id}:{$this->employee->id}";
    FbsChange::withoutGlobalScopes()->delete();

    $this->peers = [['app' => 'pay', 'tenant' => '7', 'base_url' => 'https://pay.fbs.test', 'types' => ['person', 'time']],
        ['app' => 'learn', 'tenant' => '4', 'base_url' => 'https://learn.fbs.test', 'types' => ['person', 'time']]];
    $this->sent = [];
    test()->hub = fn (Request $r) => match (true) {
        str_contains($r->url(), '/api/fbs-id/connector-peers') => Http::response(['success' => true, 'data' => ['peers' => $this->peers]]),
        str_ends_with($r->url(), '/api/fbs-id/connector-token') => Http::response(['success' => true, 'data' => ['token' => "tok-{$r['to_app']}", 'expires_in' => 300]]),
        str_ends_with($r->url(), '/api/fbs/connector/changes') => (function () use ($r) {
            $this->sent[] = [parse_url($r->url(), PHP_URL_HOST), $r['changes']];

            return Http::response(['success' => true, 'data' => ['answers' => collect($r['changes'])->mapWithKeys(fn ($c) => [$c['change_id'] => 'accepted'])]]);
        })(),
        default => null,
    };
});

function connectorToken(string $from, array $claims = []): string
{
    return fbsIdJwt($claims + ['aud' => 'people', 'sub' => "app:{$from}", 'tenant' => (string) test()->company->id, 'from' => ['app' => $from, 'tenant' => '7'],
        'scope' => 'connector.person.write', 'jti' => (string) Str::uuid(), 'iat' => time()]);
}

function changeFrom(string $app, string $externalId, array $fields, array $over = []): array
{
    return $over + ['change_id' => (string) Str::ulid(), 'type' => 'person', 'external_id' => $externalId, 'origin' => ['app' => 'people', 'external_id' => $externalId],
        'changed_by' => ['app' => $app, 'first_name' => 'Pia', 'last_name' => 'Lim'], 'changed_at' => now()->toIso8601String(), 'fields' => $fields];
}

function sendToPeople(string $from, array $changes, ?string $token = null): TestResponse
{
    app('auth')->forgetGuards();

    return test()->withToken($token ?? connectorToken($from))->putJson('/api/fbs/connector/changes', ['changes' => $changes]);
}

it('takes a change from Pay and passes it on to Learn, not back to Pay', function () {
    $c = changeFrom('pay', $this->id, ['mobile' => '0999', 'department' => 'Finance']);

    sendToPeople('pay', [$c])->assertOk()->assertJsonPath("data.answers.{$c['change_id']}", 'accepted');
    $e = $this->employee->fresh();
    expect($e->mobile)->toBe('0999')->and(Company::run($this->company, fn () => $e->department?->name))->toBe('Finance');

    (new PushFbsChanges($this->company->id))->handle();

    expect(collect($this->sent)->pluck(0)->all())->toBe(['learn.fbs.test'])
        ->and($this->sent[0][1][0]['change_id'])->toBe($c['change_id'])
        ->and($this->sent[0][1][0]['external_id'])->toBe($this->id)
        ->and($this->sent[0][1][0]['changed_by']['app'])->toBe('pay');
});

it('finds a person Learn sent by Learn\'s id, and passes the change on under People\'s id', function () {
    sendToPeople('learn', [changeFrom('learn', 'learn:4:faculty:9', ['first_name' => 'Anna'])])->assertOk();
    (new PushFbsChanges($this->company->id))->handle();

    expect($this->employee->fresh()->first_name)->toBe('Anna')
        ->and(collect($this->sent)->pluck(0)->all())->toBe(['pay.fbs.test'])
        ->and($this->sent[0][1][0]['external_id'])->toBe($this->id);
});

it('sends People\'s own edits to every linked app', function () {
    Company::run($this->company, fn () => $this->employee->fresh()->update(['mobile' => '0911']));

    (new PushFbsChanges($this->company->id))->handle();

    expect(collect($this->sent)->pluck(0)->sort()->values()->all())->toBe(['learn.fbs.test', 'pay.fbs.test'])
        ->and($this->sent[0][1][0]['fields'])->toBe(['mobile' => '0911'])
        ->and($this->sent[0][1][0]['changed_by']['app'])->toBe('people')
        ->and(FbsChange::withoutGlobalScopes()->sole()->status)->toBe('accepted');
});

it('keeps the later change in every app', function () {
    $new = changeFrom('pay', $this->id, ['mobile' => '0919'], ['changed_at' => now()->toIso8601String()]);
    $old = changeFrom('learn', 'learn:4:faculty:9', ['mobile' => '0911'], ['changed_at' => now()->subMinute()->toIso8601String()]);

    sendToPeople('pay', [$new])->assertOk();
    sendToPeople('learn', [$old])->assertOk()->assertJsonPath("data.answers.{$old['change_id']}", 'superseded');
    (new PushFbsChanges($this->company->id))->handle();

    expect($this->employee->fresh()->mobile)->toBe('0919')
        ->and(collect($this->sent)->flatMap(fn ($s) => collect($s[1])->pluck('change_id'))->all())->toBe([$new['change_id']]);
});

it('never touches another company\'s person and refuses a bad token', function () {
    [$other] = companyWithOwner('growth');
    $theirs = Company::run($other, fn () => Employee::create(['employee_number' => 'X-1', 'first_name' => 'Bo', 'last_name' => 'Lim', 'employment_type' => 'regular', 'status' => 'active']));

    $c = changeFrom('pay', "people:{$other->id}:{$theirs->id}", ['first_name' => 'Hacked']);
    sendToPeople('pay', [$c])->assertOk()->assertJsonPath("data.answers.{$c['change_id']}", 'held');
    sendToPeople('pay', [changeFrom('pay', $this->id, ['first_name' => 'X'])], connectorToken('pay', ['aud' => 'pay']))->assertUnauthorized();

    expect($theirs->fresh()->first_name)->toBe('Bo');
});

it('logs nothing for a save made from a received change', function () {
    ApplyChanges::receiving(fn () => Company::run($this->company, fn () => $this->employee->fresh()->update(['mobile' => '0900'])));

    expect(FbsChange::withoutGlobalScopes()->count())->toBe(0);
});
