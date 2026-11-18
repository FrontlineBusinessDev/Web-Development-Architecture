<?php

namespace App\Http\Controllers\FbsSync;

use App\FbsId\FbsId;
use App\FbsSync\ApplyChanges;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Support\Statuses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * PUT /api/fbs/connector/changes {changes: [...]} (contract fbs-record.v1): another FBS app's changes to shared records, with a
 * 5-minute connector token FBS Suites signed (aud `people`, `tenant` = the company, `scope` connector.{type}.write, single-use jti).
 */
class ChangesController extends Controller
{
    public function put(Request $request): JsonResponse
    {
        try {
            $claims = FbsId::verify((string) $request->bearerToken(), 'people');
        } catch (Throwable) {
            return $this->refuse(401, 'bad_token');
        }
        $jti = (string) ($claims['jti'] ?? '');
        if ($jti === '' || ! Cache::add('fbs-connector-jti:'.$jti, true, now()->addMinutes(6))) {
            return $this->refuse(401, 'replayed');
        }
        if (! preg_match('/^connector\.([a-z_]+)\.write$/', (string) ($claims['scope'] ?? ''), $m)) {
            return $this->refuse(403, 'scope');
        }
        $tenant = (string) ($claims['tenant'] ?? '');
        $company = ctype_digit($tenant) ? Company::query()->whereKey((int) $tenant)->where('status', Statuses::ACTIVE)->whereNull('sandbox_expires_at')->first() : null;
        if ($company === null) {
            return $this->refuse(404, 'no_tenant');
        }
        $changes = $request->input('changes');
        if (! is_array($changes) || ! array_is_list($changes) || count($changes) > 500) {
            return response()->json(['success' => false, 'message' => 'Send up to 500 changes as a list.', 'data' => ['reason' => 'bad_request']], 422);
        }

        $from = (string) ($claims['from']['app'] ?? '');
        $answers = Company::run($company, fn () => ApplyChanges::run($changes, $from, $m[1]));

        return response()->json(['success' => true, 'message' => '', 'data' => ['answers' => $answers]]);
    }

    private function refuse(int $status, string $reason): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'FBS People refused the connector token.', 'data' => ['reason' => $reason]], $status);
    }
}
