<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Team;

use App\Controllers\Api\V1\ApiV1Controller;
use App\Services\TeamAccess;
use App\Services\TeamConflictException;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;
use JsonException;
use RuntimeException;
use stdClass;
use Throwable;

/** Strict bearer Team API foundation; intentionally independent of browser session/CSRF state. */
abstract class TeamApiController extends ApiV1Controller
{
    protected function teamRun(callable $operation): ResponseInterface
    {
        try {
            if (!config('TeamWorkspace')->enabled) {
                return $this->teamFail(404, 'not_found', 'Team Workspace is unavailable.');
            }
            return $operation();
        } catch (JsonException) {
            return $this->teamFail(400, 'bad_request', 'Invalid JSON body.');
        } catch (TeamConflictException $error) {
            return $this->teamFail(409, $error->reason, $error->getMessage());
        } catch (RuntimeException $error) {
            $status = in_array($error->getCode(), [400, 401, 403, 404, 409, 413, 422, 429, 503], true)
                ? $error->getCode() : 503;
            $code = match ($status) {
                400 => 'bad_request', 401 => 'unauthenticated', 403 => 'forbidden', 404 => 'not_found',
                409 => 'conflict', 413 => 'payload_too_large', 422 => 'validation_failed',
                429 => 'rate_limited', default => 'service_unavailable',
            };
            return $this->teamFail($status, $code, $status === 503
                ? 'Team Workspace is temporarily unavailable.' : $error->getMessage());
        } catch (Throwable $error) {
            log_message('error', 'Team API failure: {exception}', ['exception' => $error]);
            return $this->teamFail(500, 'server_error', 'The service could not complete the request.');
        }
    }

    protected function teamAccess(): TeamAccess
    {
        return TeamAccess::bearer(Database::connect());
    }

    protected function teamOk(array $body, int $status = 200): ResponseInterface
    {
        return $this->response->setStatusCode($status)->setHeader('Cache-Control', 'private, no-store')->setJSON($body);
    }

    protected function teamFail(int $status, string $code, string $message): ResponseInterface
    {
        return $this->response->setStatusCode($status)->setHeader('Cache-Control', 'private, no-store')
            ->setJSON(['error' => ['code' => $code, 'message' => $message]]);
    }

    /** @param list<string> $allowed */
    protected function query(array $allowed): array
    {
        $input = $this->request->getGet();
        $this->only($input, $allowed);
        return $input;
    }

    /** Decode through objects first so nested JSON object/list distinctions are retained. */
    protected function body(int $maxBytes = 65536): array
    {
        $contentType = strtolower(trim(explode(';', $this->request->getHeaderLine('Content-Type'), 2)[0]));
        if ($contentType !== 'application/json') {
            throw new RuntimeException('Content-Type must be application/json.', 400);
        }
        $raw = (string) $this->request->getBody();
        if (strlen($raw) > $maxBytes || (int) $this->request->getHeaderLine('Content-Length') > $maxBytes) {
            throw new RuntimeException('Request body too large.', 413);
        }
        $decoded = json_decode($raw, false, 16, JSON_THROW_ON_ERROR);
        if (!$decoded instanceof stdClass) {
            throw new RuntimeException('JSON body must be an object.', 400);
        }
        return (array) $decoded;
    }

    /** @param list<string> $allowed */
    protected function only(array $input, array $allowed): void
    {
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw new RuntimeException('Unsupported input fields.', 422);
        }
    }

    protected function nonce(array $body): string
    {
        $nonce = $body['client_nonce'] ?? null;
        if (!is_string($nonce) || !preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $nonce)) {
            throw new RuntimeException('client_nonce must be a UUID v4.', 422);
        }
        return strtolower($nonce);
    }

    protected function id(string $value): int
    {
        if (!preg_match('/\A[1-9][0-9]*\z/', $value)
            || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new RuntimeException('Invalid resource identifier.', 404);
        }
        return (int) $value;
    }

    /** @return list<mixed> */
    protected function objectList(mixed $value, string $field): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new RuntimeException($field . ' must be a list.', 422);
        }
        $list = [];
        foreach ($value as $item) {
            if (!$item instanceof stdClass) {
                throw new RuntimeException($field . ' must contain objects.', 422);
            }
            $list[] = (array) $item;
        }
        return $list;
    }

    /** @return list<mixed> */
    protected function scalarList(mixed $value, string $field): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new RuntimeException($field . ' must be a list.', 422);
        }
        foreach ($value as $item) {
            if (is_array($item) || is_object($item)) {
                throw new RuntimeException($field . ' must contain scalar values.', 422);
            }
        }
        return $value;
    }
}
