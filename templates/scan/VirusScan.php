<?php

namespace App\Support;

use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The virus check before an upload is stored (rule 10). Copied from Web-Development-Architecture templates/scan/
 * (integrations/fbs-suites-scanner.md); keep it in step with the template.
 *
 * Drivers (config/scan.php): `fbs` streams the file to FBS Suites (`POST {FBS_SCAN_URL}/scan/{app}`, signed with
 * FBS_SCAN_SECRET), `clamav` talks to a local clamd (development), `none` checks nothing. `infected` is always refused;
 * when the scanner can't answer, the purpose's policy decides: refuse (default), hold or open.
 *
 * Returns 'clean', 'held' (store it as pending, openable by no one else), 'unchecked' (store it; policy open) or
 * 'off' (no scanner set). Throws a ValidationException on `$field` when the upload must not be stored.
 */
final class VirusScan
{
    /** @var (callable(string $path, string $purpose): array{verdict: string, signature?: string|null, reason?: string|null})|null */
    private static $stub = null;

    /** Tests: answer every scan with the stub's verdict array (`['verdict' => 'clean']`, `['verdict' => 'unavailable']`, ...). */
    public static function using(?callable $stub): void
    {
        self::$stub = $stub;
    }

    public static function clean(UploadedFile $file, string $purpose, ?string $tenant = null, string $field = 'file'): string
    {
        return self::cleanPath((string) $file->getRealPath(), $purpose, $tenant, $field);
    }

    /** clean() for a file on disk that did not come in as an upload (a ZIP entry, a staged file). */
    public static function cleanPath(string $path, string $purpose, ?string $tenant = null, string $field = 'file'): string
    {
        $driver = self::$stub !== null ? 'stub' : self::driver();
        if ($driver === 'none') {
            return 'off';
        }

        $size = (int) @filesize($path);
        if ($size > (int) config('scan.async_max_bytes', 100 * 1024 * 1024)) {
            self::refuse($field, 'This file is too large or too deeply packed to check for viruses (limit 100 MB).');
        }

        $scanId = (string) Str::uuid();
        $result = match ($driver) {
            'stub' => (self::$stub)($path, $purpose),
            'fbs' => self::askSuites($path, $size, $scanId, $purpose, $tenant),
            default => self::askClamd($path),
        };
        $code = strtoupper(substr($scanId, 0, 8));

        return match ($result['verdict']) {
            'clean' => 'clean',
            'infected' => self::refuse($field, "This file can't be uploaded: the virus check found a threat in it. If you think this is wrong, contact support with code {$code}."),
            'error' => match ($result['reason'] ?? null) {
                'encrypted' => self::refuse($field, "This file is password-protected, so it can't be checked for viruses. Upload it without a password."),
                'too_complex', 'too_large', 'use_async' => self::refuse($field, 'This file is too large or too deeply packed to check for viruses (limit 100 MB).'),
                default => self::policy($purpose, $field),
            },
            default => self::policy($purpose, $field),
        };
    }

    /** fbs when both env values are set, else SCAN_DRIVER (clamav needs CLAMAV_HOST), else none. */
    public static function driver(): string
    {
        if (config('scan.fbs.url') && config('scan.fbs.secret')) {
            return 'fbs';
        }

        return config('scan.driver') === 'clamav' && config('scan.clamav.host') ? 'clamav' : 'none';
    }

    /** The inline request's signature (integrations/fbs-suites-scanner.md section 2, with its test vectors). */
    public static function sign(string $secret, int $timestamp, string $scanId, string $sha256, int $length, ?string $tenant, string $purpose): string
    {
        return 'sha256='.hash_hmac('sha256', "{$timestamp}.{$scanId}.{$sha256}.{$length}.".($tenant ?? '').".{$purpose}", $secret);
    }

    /** Hub-style signature (timestamp + "." + raw body) for async jobs and the result callback. */
    public static function signBody(string $secret, int $timestamp, string $body): string
    {
        return 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    /** @return array{verdict: string, signature?: string|null, reason?: string|null} */
    private static function askSuites(string $path, int $size, string $scanId, string $purpose, ?string $tenant): array
    {
        $secret = (string) config('scan.fbs.secret');
        $url = rtrim((string) config('scan.fbs.url'), '/').'/scan/'.config('scan.app');
        $sha256 = (string) hash_file('sha256', $path);
        $timestamp = now()->getTimestamp();
        $file = fopen($path, 'rb');
        if ($file === false) {
            return ['verdict' => 'unavailable', 'reason' => 'unreadable'];
        }

        try {
            $response = Http::connectTimeout(2)->timeout(30)->withoutRedirecting()->acceptJson()
                ->withHeaders(array_filter([
                    'X-FBS-Scan-Id' => $scanId,
                    'X-FBS-Timestamp' => (string) $timestamp,
                    'X-FBS-Content-SHA256' => $sha256,
                    'X-FBS-Tenant' => $tenant,
                    'X-FBS-Purpose' => $purpose,
                    'X-FBS-Signature' => self::sign($secret, $timestamp, $scanId, $sha256, $size, $tenant, $purpose),
                    'Content-Length' => (string) $size,
                ], fn ($v) => $v !== null && $v !== ''))
                ->withBody(Utils::streamFor($file), 'application/octet-stream')
                ->post($url);
        } catch (ConnectionException) {
            Log::warning('Virus scan: FBS Suites did not answer.', ['scan_id' => $scanId]);

            return ['verdict' => 'unavailable', 'reason' => 'unreachable'];
        } finally {
            if (is_resource($file)) {
                fclose($file);
            }
        }

        $data = (array) $response->json('data', []);
        if (in_array($response->status(), [200, 409], true) && in_array($data['verdict'] ?? null, ['clean', 'infected', 'error'], true)) {
            return ['verdict' => $data['verdict'], 'signature' => $data['signature'] ?? null, 'reason' => $data['reason'] ?? null];
        }
        if ($response->status() === 413) {
            return ['verdict' => 'error', 'reason' => 'use_async'];
        }
        if (in_array($response->status(), [400, 401], true)) {
            // A wrong FBS_SCAN_SECRET, a clock off by minutes or a broken stream: staff need to know.
            Log::error('Virus scan refused by FBS Suites.', ['scan_id' => $scanId, 'status' => $response->status(), 'reason' => $data['reason'] ?? null]);
        }

        return ['verdict' => 'unavailable', 'reason' => (string) ($data['reason'] ?? 'http_'.$response->status())];
    }

    /**
     * Async scan for a file over the inline limit that the app has already put where no one can open it
     * (section 4 of the contract). Returns the scan id; the verdict arrives at POST /api/fbs-scan/result
     * (SuiteScanResultController). Null when FBS Suites refused or did not answer: keep the file pending.
     */
    public static function queue(string $presignedUrl, int $size, string $sha256, string $purpose, ?string $tenant = null): ?string
    {
        if (self::driver() !== 'fbs') {
            return null;
        }
        $scanId = (string) Str::uuid();
        $body = (string) json_encode(['scan_id' => $scanId, 'url' => $presignedUrl, 'size' => $size, 'sha256' => $sha256,
            'tenant' => $tenant, 'purpose' => $purpose, 'callback' => true], JSON_UNESCAPED_SLASHES);
        $timestamp = now()->getTimestamp();

        try {
            $response = Http::connectTimeout(2)->timeout(10)->withoutRedirecting()->acceptJson()
                ->withHeaders([
                    'X-FBS-Event-Id' => $scanId,
                    'X-FBS-Timestamp' => (string) $timestamp,
                    'X-FBS-Signature' => self::signBody((string) config('scan.fbs.secret'), $timestamp, $body),
                ])
                ->withBody($body, 'application/json')
                ->post(rtrim((string) config('scan.fbs.url'), '/').'/scan/'.config('scan.app').'/jobs');
        } catch (ConnectionException) {
            return null; // the presigned URL is a credential: never log it
        }

        return $response->status() === 202 ? $scanId : null;
    }

    /** @return array{verdict: string, signature?: string|null, reason?: string|null} local clamd, development only */
    private static function askClamd(string $path): array
    {
        $timeout = (int) config('scan.clamav.timeout', 20);
        $fp = @stream_socket_client('tcp://'.config('scan.clamav.host').':'.config('scan.clamav.port'), $code, $message, $timeout);
        $file = fopen($path, 'rb');
        if ($fp === false || $file === false) {
            return ['verdict' => 'unavailable', 'reason' => 'unreachable'];
        }
        stream_set_timeout($fp, $timeout);
        try {
            fwrite($fp, "zINSTREAM\0");
            while (! feof($file) && ($chunk = fread($file, 65536)) !== false && $chunk !== '') {
                fwrite($fp, pack('N', strlen($chunk)).$chunk);
            }
            fwrite($fp, pack('N', 0));
            $reply = trim((string) stream_get_contents($fp), "\0 \n\r");
        } finally {
            fclose($file);
            fclose($fp);
        }

        return match (true) {
            str_ends_with($reply, ' OK') => ['verdict' => 'clean'],
            str_contains($reply, 'Heuristics.Encrypted') => ['verdict' => 'error', 'reason' => 'encrypted'],
            str_contains($reply, 'Heuristics.Limits.Exceeded'), str_contains($reply, 'size limit exceeded') => ['verdict' => 'error', 'reason' => 'too_complex'],
            preg_match('/^stream: (.+) FOUND$/', $reply, $m) === 1 => ['verdict' => 'infected', 'signature' => $m[1]],
            default => ['verdict' => 'unavailable', 'reason' => 'bad_reply'],
        };
    }

    /** The scanner couldn't answer: this purpose's policy (config/scan.php `policies`, default refuse). */
    private static function policy(string $purpose, string $field): string
    {
        return match (config("scan.policies.{$purpose}", 'refuse')) {
            'hold' => 'held',
            'open' => 'unchecked',
            default => self::refuse($field, "We couldn't check this file for viruses just now, so it wasn't saved. Try again in a minute."),
        };
    }

    private static function refuse(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
