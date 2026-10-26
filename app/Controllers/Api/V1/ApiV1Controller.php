<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\BaseController;
use App\Integrations\Mis\MisUnavailableException;
use App\Services\Api\ApiContext;
use CodeIgniter\HTTP\ResponseInterface;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Mobile API base: one response envelope and one error contract for every endpoint.
 * Success bodies are resources (collections: {data, meta}); failures are {error: {code, message, fields?}}.
 */
abstract class ApiV1Controller extends BaseController
{
    private const STATUS_CODES = [
        400 => 'bad_request',
        401 => 'unauthenticated',
        403 => 'forbidden',
        404 => 'not_found',
        409 => 'conflict',
        413 => 'payload_too_large',
        422 => 'validation_failed',
        429 => 'rate_limited',
        503 => 'service_unavailable',
    ];

    protected function ok(mixed $body, int $status = 200): ResponseInterface
    {
        return $this->response->setStatusCode($status)
            ->setHeader('Cache-Control', 'private, no-store')->setJSON($body);
    }

    protected function noContent(): ResponseInterface
    {
        return $this->response->setStatusCode(204)->setHeader('Cache-Control', 'private, no-store');
    }

    /** @param array<string, list<string>> $fields */
    protected function fail(int $status, string $code, string $message, array $fields = []): ResponseInterface
    {
        $error = ['code' => $code, 'message' => $message];
        if ($fields !== []) {
            $error['fields'] = $fields;
        }
        $response = $this->response->setStatusCode($status)->setHeader('Cache-Control', 'private, no-store');
        if ($status === 429) {
            $response->setHeader('Retry-After', '60');
        }
        return $response->setJSON(['error' => $error]);
    }

    protected function run(callable $operation): ResponseInterface
    {
        try {
            return $operation();
        } catch (MisUnavailableException) {
            // Never expose upstream bodies, credentials, API keys, or transport details.
            return $this->fail(503, 'service_unavailable',
                'MIS sign-in is currently unavailable. Connect to the university network and try again, or contact support.');
        } catch (\DomainException $error) {
            $status = (int) $error->getCode();
            if (!isset(self::STATUS_CODES[$status])) {
                $status = 403;
            }
            return $this->fail($status, self::STATUS_CODES[$status], $error->getMessage());
        } catch (JsonException $error) {
            return $this->fail(400, 'bad_request', 'Invalid JSON body.');
        } catch (InvalidArgumentException $error) {
            $status = (int) $error->getCode();
            if (!isset(self::STATUS_CODES[$status])) {
                $status = 400;
            }
            return $this->fail($status, self::STATUS_CODES[$status], $error->getMessage());
        } catch (RuntimeException $error) {
            $status = (int) $error->getCode();
            if (isset(self::STATUS_CODES[$status])) {
                return $this->fail($status, self::STATUS_CODES[$status], $error->getMessage());
            }
            log_message('error', 'Mobile API failure: {exception}', ['exception' => $error]);
            return $this->fail(503, 'service_unavailable', 'The service is temporarily unavailable. Please try again shortly.');
        } catch (Throwable $error) {
            return $this->serverError($error);
        }
    }

    /** @return array<string, mixed> */
    protected function jsonBody(int $maxBytes = 16384): array
    {
        $body = (string) $this->request->getBody();
        if (strlen($body) > $maxBytes || (int) $this->request->getHeaderLine('Content-Length') > $maxBytes) {
            throw new RuntimeException('Request body too large.', 413);
        }
        $decoded = json_decode($body, false, 16, JSON_THROW_ON_ERROR);
        if (!$decoded instanceof stdClass) {
            throw new InvalidArgumentException('JSON body must be an object.');
        }
        return (array) $decoded;
    }

    /** @return array<string, mixed> */
    protected function identity(): array
    {
        return ApiContext::identity();
    }

    /** Client-safe identity view; session bookkeeping keys never cross the wire. */
    protected function presentUser(array $identity): array
    {
        unset($identity['logged_in']);
        return $identity;
    }

    private function serverError(Throwable $error): ResponseInterface
    {
        log_message('error', 'Mobile API failure: {exception}', ['exception' => $error]);
        return $this->fail(500, 'server_error', 'The service could not complete the request.');
    }
}
