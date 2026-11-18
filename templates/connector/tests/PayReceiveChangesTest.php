<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\FbsChange;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

// Shared records both ways (fbs-suites-mcp plan 2026-11-18-every-app-two-way-sync; contract fbs-record.v1): Pay takes a person's changes.

beforeEach(function () {
    fakeFbsId();
    $this->company = Company::factory()->create();
    $this->employee = Employee::factory()->create(['company_id' => $this->company->id, 'branch_id' => Branch::factory()->create(['company_id' => $this->company->id])->id,
        'personal' => ['firstName' => 'Ana', 'lastName' => 'Cruz', 'contactNumber' => '0917'],
        'employment' => ['department' => 'Sales', 'position' => 'Clerk', 'status' => 'active', 'dateHired' => '2025-01-06', 'employmentType' => 'regular', 'category' => 'regular'],
    ]);
    $this->employee->forceFill(['external_id' => 'people:5:10'])->save();
    FbsChange::withoutGlobalScopes()->delete(); // the setup's own saves
});

function hubToken(array $claims = []): string
{
    return fbsJwt($claims + ['aud' => 'pay', 'sub' => 'app:people', 'tenant' => (string) test()->company->id, 'from' => ['app' => 'people', 'tenant' => '5'],
        'scope' => 'connector.person.write', 'jti' => (string) Str::uuid(), 'iat' => time()]);
}

function personChange(array $fields, array $over = []): array
{
    return $over + ['change_id' => (string) Str::ulid(), 'type' => 'person', 'external_id' => 'people:5:10',
        'origin' => ['app' => 'people', 'external_id' => 'people:5:10'], 'changed_by' => ['app' => 'people', 'first_name' => 'Hal', 'last_name' => 'Reyes'],
        'changed_at' => now()->toIso8601String(), 'fields' => $fields];
}

function pushChanges(array $changes, ?string $token = null): TestResponse
{
    app('auth')->forgetGuards();

    return test()->withToken($token ?? hubToken())->withHeader('Idempotency-Key', (string) Str::uuid())
        ->putJson('/api/fbs/connector/changes', ['changes' => $changes]);
}

test('a person change from People updates the linked employee', function () {
    $c = personChange(['first_name' => 'Anna', 'mobile' => '0918', 'department' => 'Finance']);

    pushChanges([$c])->assertOk()->assertJsonPath("data.answers.{$c['change_id']}", 'accepted');

    $e = $this->employee->fresh();
    expect($e->first_name)->toBe('Anna')->and($e->personal['contactNumber'])->toBe('0918')->and($e->department)->toBe('Finance')
        ->and($e->last_name)->toBe('Cruz');
});

test('a repeat change id is unchanged', function () {
    $c = personChange(['first_name' => 'Anna']);
    pushChanges([$c])->assertOk();

    pushChanges([$c])->assertOk()->assertJsonPath("data.answers.{$c['change_id']}", 'unchanged');
});

test('the later change wins and the older one is superseded', function () {
    $new = personChange(['mobile' => '0919'], ['changed_at' => now()->toIso8601String()]);
    $old = personChange(['mobile' => '0911'], ['changed_at' => now()->subMinute()->toIso8601String()]);

    pushChanges([$new])->assertOk();
    pushChanges([$old])->assertOk()->assertJsonPath("data.answers.{$old['change_id']}", 'superseded');

    expect($this->employee->fresh()->personal['contactNumber'])->toBe('0919')
        ->and(FbsChange::withoutGlobalScopes()->where('change_id', $old['change_id'])->value('status'))->toBe('superseded');
});

test('a received change is never queued to be sent again', function () {
    pushChanges([personChange(['first_name' => 'Anna'])])->assertOk();

    expect(FbsChange::withoutGlobalScopes()->whereNull('received_from')->count())->toBe(0);
});

test('a person this company does not have is held, and another company\'s person is never touched', function () {
    $other = otherCompany();
    $theirs = Employee::factory()->create(['company_id' => $other->id, 'branch_id' => Branch::factory()->create(['company_id' => $other->id])->id,
        'personal' => ['firstName' => 'Bo', 'lastName' => 'Lim']]);
    $theirs->forceFill(['external_id' => 'people:9:1'])->save();
    $c = personChange(['first_name' => 'Hacked'], ['external_id' => 'people:9:1']);

    pushChanges([$c])->assertOk()->assertJsonPath("data.answers.{$c['change_id']}", 'held');

    expect($theirs->fresh()->first_name)->toBe('Bo');
});

test('expired, replayed, wrong-audience and wrong-scope tokens are refused', function () {
    $token = hubToken();
    pushChanges([personChange(['first_name' => 'X'])], $token)->assertOk();
    pushChanges([personChange(['first_name' => 'X'])], $token)->assertUnauthorized();
    pushChanges([personChange(['first_name' => 'X'])], hubToken(['exp' => time() - 120]))->assertUnauthorized();
    pushChanges([personChange(['first_name' => 'X'])], hubToken(['aud' => 'people']))->assertUnauthorized();
    pushChanges([personChange(['first_name' => 'X'])], hubToken(['scope' => 'connector.person.read']))->assertForbidden();
    pushChanges([personChange(['first_name' => 'X'], ['type' => 'product'])])->assertOk()->assertJsonPath('data.answers', fn ($a) => array_values($a)[0] === 'refused');
});
