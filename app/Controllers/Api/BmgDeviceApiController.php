<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Services\Facilities\BmgRotationService;
use CodeIgniter\HTTP\ResponseInterface;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Throwable;

final class BmgDeviceApiController extends BaseController
{
    public function schedule(): ResponseInterface
    {
        return $this->respond(fn (BmgRotationService $service, string $token): array => $service->deviceSchedule($token));
    }

    public function heartbeat(): ResponseInterface
    {
        return $this->respond(fn (BmgRotationService $service, string $token): array => $service->heartbeat($token, $this->payload()));
    }

    public function runs(): ResponseInterface
    {
        return $this->respond(fn (BmgRotationService $service, string $token): array => $service->ingestRun($token, $this->payload()), true);
    }

    public function commands(): ResponseInterface
    {
        return $this->respond(fn (BmgRotationService $service, string $token): array => $service->pendingCommands($token));
    }

    public function acknowledge(): ResponseInterface
    {
        return $this->respond(fn (BmgRotationService $service, string $token): array => $service->acknowledgeCommand($token, $this->payload()));
    }

    private function respond(callable $operation, bool $created = false): ResponseInterface
    {
        $this->response->setHeader('Cache-Control', 'no-store');
        if (is_file(WRITEPATH . 'bmg-maintenance.flag')) {
            return $this->response->setStatusCode(503)->setHeader('Retry-After', '60')
                ->setJSON(['error' => 'Drum operations are temporarily unavailable for maintenance.']);
        }
        try {
            $token = $this->request->getHeaderLine('X-Device-Token');
            // Bound unauthenticated traffic as well as aggregate traffic across device endpoints.
            $throttler = service('throttler');
            if (!$throttler->check('bmg-rotation-ip-' . hash('sha256', $this->request->getIPAddress()), 120, 60)
                || ($token !== '' && !$throttler->check('bmg-rotation-token-' . hash('sha256', $token), 60, 60))) {
                $this->response->setHeader('Retry-After', '60');
                throw new RuntimeException('Device API rate limit exceeded.', 429);
            }
            if ($token === '') { throw new RuntimeException('Unauthorized device token.', 401); }
            $result = $operation(new BmgRotationService(), $token);
            return $this->response->setStatusCode($created && ($result['status'] ?? '') === 'created' ? 201 : 200)->setJSON($result);
        } catch (InvalidArgumentException | JsonException $error) {
            return $this->response->setStatusCode(400)->setJSON(['error' => $error instanceof JsonException ? 'Invalid JSON body.' : $error->getMessage()]);
        } catch (RuntimeException $error) {
            if (in_array($error->getCode(), [401, 403, 404, 409, 413, 429], true)) {
                return $this->response->setStatusCode($error->getCode())->setJSON(['error' => $error->getMessage()]);
            }
            log_message('error', 'BMG rotation API failure: {exception}', ['exception' => $error]);
            return $this->response->setStatusCode(503)->setJSON(['error' => 'Rotation service unavailable.']);
        } catch (Throwable $error) {
            log_message('error', 'BMG rotation API failure: {exception}', ['exception' => $error]);
            return $this->response->setStatusCode(503)->setJSON(['error' => 'Rotation service unavailable.']);
        }
    }

    private function payload(): array
    {
        $body = (string) $this->request->getBody();
        if (strlen($body) > 8192 || (int) $this->request->getHeaderLine('Content-Length') > 8192) {
            throw new RuntimeException('Request body too large.', 413);
        }
        if (str_contains(strtolower($this->request->getHeaderLine('Content-Type')), 'application/json')) {
            $object = json_decode($body, false, 16, JSON_THROW_ON_ERROR);
            if (!$object instanceof \stdClass) { throw new InvalidArgumentException('JSON body must be an object.'); }
            return (array) $object;
        }
        return $this->request->getPost() ?? [];
    }
}
