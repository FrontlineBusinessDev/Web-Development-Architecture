<?php

namespace App\Support;

use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * The virus check before an upload is stored (rule 10), against FBS Suites' scanner (Web-Development-Architecture
 * integrations/fbs-suites-scanner.md). Copied from templates/scan/; FBS People (App\Support\VirusScan) is the reference,
 * with hold and async built on top (App\Files\ScanOutcome, `files:rescan`). Change only tenant() when you copy it.
 *
 * Drivers (config/scan.php): `fbs` streams the file to `POST {FBS_SCAN_URL}/scan/{app}`, signed with FBS_SCAN_SECRET
 * (on when both are set); `clamav` asks a local clamd (development); `none` checks nothing, unless `scan.required`.
 * An infected file is always refused. When the file can't be checked, the purpose's policy decides (`scan.policies`):
 * refuse (default), hold (store it as pending, nobody else opens it until a re-scan passes) or open (store it, logged).
 *
 * clean() / cleanPath() return CLEAN, HELD, UNCHECKED, ASYNC (an async-capable caller's file is over the inline limit:
 * store it as pending and call queue()) or OFF (no scanner set); anything that must not be stored throws a 422 on $field.
 */
final class VirusScan
{
    public const CLEAN = 'clean';

    public const HELD = 'held';

    public const UNCHECKED = 'unchecked';

    public const ASYNC = 'async';

    public const OFF = 'off';

    /** @var (callable(string, string): (string|array<string, mixed>))|null tests: gets the path and purpose, returns clamd's reply or a verdict */
    private static $stub = null;

    /**
     * Tests: answer every scan with the stub, either clamd's one-line reply ("stream: OK", "stream: Eicar FOUND") or a verdict
     * array (['verdict' => 'clean' | 'infected' | 'error' | 'unavailable', 'signature' => ..., 'reason' => ...]). A stub
     * that throws means "the scanner could not be reached".
     *
     * @param  (callable(string, string): (string|array<string, mixed>))|null  $stub
     */
    public static function using(?callable $stub): void
    {
        self::$stub = $stub;
    }

    public static function clean(UploadedFile $file, string $purpose, string $field = 'file', bool $async = false): string
    {
        return self::cleanPath((string) $file->getRealPath(), $purpose, $field, $async);
    }

    /** clean() for a file on disk that did not come in as an upload, like one taken out of a ZIP. */
    public static function cleanPath(string $path, string $purpose, string $field = 'file', bool $async = false): string
    {
        $driver = self::$stub !== null ? 'stub' : self::driver();
        if ($driver === 'none') {
            self::refuseWhen((bool) config('scan.required'), 'File checking is not available right now, so files cannot be added. Try again later.', $field);

            return self::OFF;
        }

        $size = (int) @filesize($path);
        self::refuseWhen($size > (int) config('scan.async_max_bytes'), self::TOO_BIG, $field);
        if ($driver === 'fbs' && $size > (int) config('scan.inline_max_bytes')) {
            self::refuseWhen(! $async, self::TOO_BIG_INLINE, $field);

            return self::ASYNC;
        }

        $scanId = (string) Str::uuid();
        $result = self::result($path, $purpose, $scanId);

        return self::decide($result, $purpose, $field, $scanId);
    }

    /**
     * One scan's verdict, never throwing: `verdict` clean | infected | error | unavailable, with `signature` / `reason`.
     * The re-scan of held files uses it directly.
     *
     * @return array{verdict: string, signature?: string|null, reason?: string|null}
     */
    public static function result(string $path, string $purpose, ?string $scanId = null): array
    {
        try {
            return match (self::$stub !== null ? 'stub' : self::driver()) {
                'stub' => self::normalize((self::$stub)($path, $purpose)),
                'fbs' => self::askSuites($path, $scanId ?? (string) Str::uuid(), $purpose),
                'clamav' => self::fromReply(self::askClamd($path)),
                default => ['verdict' => 'unavailable', 'reason' => 'off'],
            };
        } catch (Throwable $e) {
            report($e);

            return ['verdict' => 'unavailable', 'reason' => 'unreachable'];
        }
    }

    /** fbs when FBS_SCAN_URL and FBS_SCAN_SECRET are set, else `clamav` with a host, else none. */
    public static function driver(): string
    {
        if (config('scan.fbs.url') && config('scan.fbs.secret')) {
            return 'fbs';
        }

        return config('scan.driver') === 'clamav' && config('scan.host') ? 'clamav' : 'none';
    }

    /** The policy when a file can't be checked: refuse (default) | hold | open. */
    public static function policy(string $purpose): string
    {
        return (string) (config("scan.policies.{$purpose}") ?? 'refuse');
    }

    /** The inline request's signature (contract section 2 and its test vectors). */
    public static function sign(string $secret, int $timestamp, string $scanId, string $sha256, int $length, ?string $tenant, string $purpose): string
    {
        return 'sha256='.hash_hmac('sha256', "{$timestamp}.{$scanId}.{$sha256}.{$length}.".($tenant ?? '').".{$purpose}", $secret);
    }

    /** Hub-style (timestamp + "." + raw body): async jobs and the result callback. */
    public static function signBody(string $secret, int $timestamp, string $body): string
    {
        return 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    /** A poll's signature: timestamp + "." + scan id. */
    public static function signPoll(string $secret, int $timestamp, string $scanId): string
    {
        return 'sha256='.hash_hmac('sha256', "{$timestamp}.{$scanId}", $secret);
    }

    /**
     * Async scan of a stored, still-pending file through a presigned URL (contract section 4). Returns the scan id, or null
     * when FBS Suites refused or did not answer (keep the file pending; the re-scan job tries again).
     */
    public static function queue(#[\SensitiveParameter] string $presignedUrl, int $size, string $sha256, string $purpose): ?string
    {
        if (self::driver() !== 'fbs') {
            return null;
        }
        $scanId = (string) Str::uuid();
        $body = (string) json_encode(['scan_id' => $scanId, 'url' => $presignedUrl, 'size' => $size, 'sha256' => $sha256,
            'tenant' => self::tenant(), 'purpose' => $purpose, 'callback' => true], JSON_UNESCAPED_SLASHES);
        $timestamp = now()->getTimestamp();

        try {
            $response = Http::connectTimeout(2)->timeout(10)->withoutRedirecting()->acceptJson()
                ->withHeaders(['X-FBS-Event-Id' => $scanId, 'X-FBS-Timestamp' => (string) $timestamp,
                    'X-FBS-Signature' => self::signBody((string) config('scan.fbs.secret'), $timestamp, $body)])
                ->withBody($body, 'application/json')
                ->post(self::endpoint().'/jobs');
        } catch (ConnectionException) {
            return null; // the presigned URL is a credential: never logged
        }

        return $response->status() === 202 ? $scanId : null;
    }

    /**
     * An async scan's state (the callback may have been lost): null while it runs or when it can't be asked, else the verdict.
     *
     * @return array{verdict: string, signature?: string|null, reason?: string|null}|array{verdict: 'unknown'}|null
     */
    public static function poll(string $scanId): ?array
    {
        if (self::driver() !== 'fbs') {
            return null;
        }
        $timestamp = now()->getTimestamp();
        try {
            $response = Http::connectTimeout(2)->timeout(10)->withoutRedirecting()->acceptJson()
                ->withHeaders(['X-FBS-Timestamp' => (string) $timestamp, 'X-FBS-Signature' => self::signPoll((string) config('scan.fbs.secret'), $timestamp, $scanId)])
                ->get(self::endpoint().'/jobs/'.$scanId);
        } catch (ConnectionException) {
            return null;
        }
        if ($response->status() === 404) {
            return ['verdict' => 'unknown']; // FBS Suites never got it: send it again
        }
        $data = (array) $response->json('data', []);

        return $response->successful() && ($data['status'] ?? null) === 'done' && in_array($data['verdict'] ?? null, ['clean', 'infected', 'error'], true)
            ? ['verdict' => $data['verdict'], 'signature' => $data['signature'] ?? null, 'reason' => $data['reason'] ?? null]
            : null;
    }

    /**
     * The person's message for a verdict that keeps a file out (contract section 6).
     *
     * @param  array{verdict: string, signature?: string|null, reason?: string|null}  $result
     */
    public static function message(array $result, ?string $scanId = null): string
    {
        $code = $scanId ? ' with code '.strtoupper(substr($scanId, 0, 8)) : '';

        return match (true) {
            $result['verdict'] === 'infected' => "This file can't be uploaded: the virus check found a threat in it. If you think this is wrong, contact support{$code}.",
            ($result['reason'] ?? null) === 'encrypted' => "This file is password-protected, so it can't be checked for viruses. Upload it without a password.",
            in_array($result['reason'] ?? null, ['too_complex', 'too_large', 'use_async'], true) => self::TOO_BIG,
            default => "We couldn't check this file for viruses just now, so it wasn't saved. Try again in a minute.",
        };
    }

    private const TOO_BIG = 'This file is too large or too deeply packed to check for viruses (limit 100 MB).';

    private const TOO_BIG_INLINE = 'This file is too large to check for viruses here (limit 25 MB).';

    /** A 422 on $field (People uses its own App\Support\Refuse for this). */
    private static function refuseWhen(bool $condition, string $message, string $field): void
    {
        if ($condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }

    /** @param  array{verdict: string, signature?: string|null, reason?: string|null}  $result */
    private static function decide(array $result, string $purpose, string $field, string $scanId): string
    {
        $fileProblem = in_array($result['reason'] ?? null, ['encrypted', 'too_complex', 'too_large', 'use_async'], true);
        if ($result['verdict'] === 'clean') {
            return self::CLEAN;
        }
        if ($result['verdict'] === 'infected' || ($result['verdict'] === 'error' && $fileProblem)) {
            self::refuseWhen(true, self::message($result, $scanId), $field);
        }

        // The scanner could not answer: this purpose's policy.
        $policy = self::policy($purpose);
        if ($policy === 'hold') {
            return self::HELD;
        }
        if ($policy === 'open') {
            Log::warning('Upload stored without a virus check (policy open).', ['purpose' => $purpose, 'reason' => $result['reason'] ?? null]);

            return self::UNCHECKED;
        }

        throw ValidationException::withMessages([$field => self::message($result)]);
    }

    /** @return array{verdict: string, signature?: string|null, reason?: string|null} */
    private static function askSuites(string $path, string $scanId, string $purpose): array
    {
        $secret = (string) config('scan.fbs.secret');
        $size = (int) filesize($path);
        $sha256 = (string) hash_file('sha256', $path);
        $tenant = self::tenant();
        $timestamp = now()->getTimestamp();
        $file = fopen($path, 'rb');
        if ($file === false) {
            return ['verdict' => 'unavailable', 'reason' => 'unreadable'];
        }

        try {
            $response = Http::connectTimeout(2)->timeout((int) config('scan.fbs.timeout', 30))->withoutRedirecting()->acceptJson()
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
                ->post(self::endpoint());
        } catch (ConnectionException) {
            Log::warning('Virus scan: FBS Suites did not answer.', ['scan_id' => $scanId]);

            return ['verdict' => 'unavailable', 'reason' => 'unreachable'];
        }
        // The stream owns the file handle and closes it when it is released.

        $data = (array) $response->json('data', []);
        if (in_array($response->status(), [200, 409], true) && in_array($data['verdict'] ?? null, ['clean', 'infected', 'error'], true)) {
            return ['verdict' => $data['verdict'], 'signature' => $data['signature'] ?? null, 'reason' => $data['reason'] ?? null];
        }
        if ($response->status() === 413) {
            return ['verdict' => 'error', 'reason' => 'use_async'];
        }
        if (in_array($response->status(), [400, 401], true)) {
            // A wrong FBS_SCAN_SECRET, a clock off by minutes, a broken stream: staff need to see it.
            Log::error('Virus scan refused by FBS Suites.', ['scan_id' => $scanId, 'status' => $response->status(), 'reason' => $data['reason'] ?? null]);
        }

        return ['verdict' => 'unavailable', 'reason' => (string) ($data['reason'] ?? 'http_'.$response->status())];
    }

    /** Streams the file to a local clamd (development) and returns its one-line reply. */
    private static function askClamd(string $path): string
    {
        $fp = @stream_socket_client('tcp://'.config('scan.host').':'.config('scan.port'), $code, $message, (float) config('scan.timeout'));
        if ($fp === false) {
            throw new RuntimeException("The virus scanner is not reachable: {$message}");
        }
        stream_set_timeout($fp, (int) config('scan.timeout'));
        $file = fopen($path, 'rb');
        if ($file === false) {
            fclose($fp);
            throw new RuntimeException('The upload could not be read for scanning.');
        }
        try {
            fwrite($fp, "zINSTREAM\0");
            while (! feof($file) && ($chunk = fread($file, 65536)) !== false && $chunk !== '') {
                fwrite($fp, pack('N', strlen($chunk)).$chunk);
            }
            fwrite($fp, pack('N', 0));

            return (string) stream_get_contents($fp);
        } finally {
            fclose($file);
            fclose($fp);
        }
    }

    /**
     * clamd's reply as a verdict: only "stream: OK" is clean; AlertExceedsMax / AlertEncrypted findings are errors.
     *
     * @return array{verdict: string, signature?: string|null, reason?: string|null}
     */
    public static function fromReply(string $reply): array
    {
        $reply = trim($reply, "\0 \n\r");

        return match (true) {
            str_ends_with($reply, ': OK') || $reply === 'OK' => ['verdict' => 'clean'],
            str_contains($reply, 'size limit exceeded') => ['verdict' => 'error', 'reason' => 'too_large'],
            preg_match('/^stream: Heuristics\.Limits\.Exceeded/', $reply) === 1 => ['verdict' => 'error', 'reason' => 'too_complex'],
            preg_match('/^stream: Heuristics\.Encrypted/', $reply) === 1 => ['verdict' => 'error', 'reason' => 'encrypted'],
            preg_match('/^stream: (.+) FOUND$/', $reply, $m) === 1 => ['verdict' => 'infected', 'signature' => $m[1]],
            default => ['verdict' => 'unavailable', 'reason' => 'bad_reply'],
        };
    }

    /**
     * @param  string|array<string, mixed>  $answer
     * @return array{verdict: string, signature?: string|null, reason?: string|null}
     */
    private static function normalize(string|array $answer): array
    {
        return is_string($answer) ? self::fromReply($answer) : ['verdict' => (string) ($answer['verdict'] ?? 'unavailable'),
            'signature' => $answer['signature'] ?? null, 'reason' => $answer['reason'] ?? null];
    }

    private static function endpoint(): string
    {
        return rtrim((string) config('scan.fbs.url'), '/').'/scan/'.config('scan.app');
    }

    /** The tenant id sent with each scan (an id, never a name). Each app returns its own here (People: its current company's id). */
    private static function tenant(): ?string
    {
        return null;
    }
}
