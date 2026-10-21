<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Facilities\BmgFirmwareService;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class BmgUsbController extends BaseController
{
    private BmgFirmwareService $firmware;

    public function __construct()
    {
        $this->firmware = new BmgFirmwareService();
    }

    public function index(int $id)
    {
        return $this->respond(fn () => view('facilities/usb', $this->firmware->workspace($id)));
    }

    public function manifest(int $id, string $releaseId)
    {
        return $this->respond(fn () => $this->response->setJSON($this->firmware->manifest($id, $releaseId)));
    }

    public function part(int $id, string $releaseId, int $partIndex)
    {
        return $this->respond(function () use ($id, $releaseId, $partIndex) {
            $part = $this->firmware->part($id, $releaseId, $partIndex);
            return $this->response->setContentType('application/octet-stream')
                ->setHeader('Content-Disposition', 'attachment; filename="' . $part['name'] . '"')
                ->setHeader('Content-Length', (string) $part['size'])->setBody($part['body']);
        });
    }

    public function authorizeFlash(int $id)
    {
        return $this->respond(function () use ($id) {
            if (str_contains(strtolower($this->request->getHeaderLine('Content-Type')), 'application/json')) {
                $length = $this->request->getHeaderLine('Content-Length');
                if ($length !== '' && (!ctype_digit($length) || (float) $length > 8192)) {
                    throw new InvalidArgumentException('JSON input exceeds the allowed size.');
                }
                $body = $this->request->getBody();
                if (!is_string($body) || strlen($body) > 8192) {
                    throw new InvalidArgumentException('JSON input exceeds the allowed size.');
                }
                try { $data = json_decode($body, true, 16, JSON_THROW_ON_ERROR); }
                catch (Throwable) { throw new InvalidArgumentException('Invalid JSON input.'); }
            } else { $data = $this->request->getPost(); }
            if (!is_array($data) || array_is_list($data)) { throw new InvalidArgumentException('An input object is required.'); }
            // CSRF itself is verified by the route's existing CSRF filter, not by this extraction.
            unset($data[csrf_token()]);
            return $this->response->setJSON($this->firmware->authorize($id, $data) + ['csrf' => $this->csrf()]);
        });
    }

    private function csrf(): array { return ['name' => csrf_token(), 'hash' => csrf_hash()]; }

    private function respond(callable $operation)
    {
        $this->response->setHeader('Cache-Control', 'private, no-store, max-age=0')
            ->setHeader('Pragma', 'no-cache')->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Referrer-Policy', 'no-referrer');
        try { return $operation(); }
        catch (Throwable $error) {
            $status = $error instanceof InvalidArgumentException ? 422
                : ($error instanceof RuntimeException && in_array($error->getCode(), [403, 404, 409, 503], true) ? $error->getCode() : 503);
            // Fixed messages avoid leaking paths, SQL, credentials, or exception causes.
            $message = match ($status) {
                422 => 'Invalid USB workspace input.', 403 => 'USB workspace access denied.',
                404 => 'Unit or approved firmware not found.',
                409 => 'Flash safety conditions changed or are not satisfied. Reload and check the unit.',
                default => 'Approved firmware or USB authorization is unavailable.',
            };
            return $this->response->setStatusCode($status)->setJSON(['success' => false, 'message' => $message, 'csrf' => $this->csrf()]);
        }
    }
}
