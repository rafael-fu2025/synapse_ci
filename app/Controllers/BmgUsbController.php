<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Facilities\BmgFirmwareService;
use App\Services\Facilities\BmgBuildService;
use App\Services\Facilities\BmgRotationService;
use Config\BmgBuild;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class BmgUsbController extends BaseController
{
    private BmgFirmwareService $firmware;
    private BmgBuildService $builds;

    public function __construct(?BmgBuildService $builds = null)
    {
        $this->firmware = new BmgFirmwareService();
        $this->builds = $builds ?? new BmgBuildService();
    }

    public function index(int $id)
    {
        return $this->respond(fn () => view('facilities/usb', $this->firmware->workspace($id)
            + ['builder' => $this->builds->status($id)]));
    }

    public function setupStatus(int $id)
    {
        return $this->respond(function () use ($id) {
            $builder = $this->builds->status($id);
            $detail = (new BmgRotationService())->unit($id);
            $device = $detail['device'] ?? [];
            return $this->response->setJSON(['builder' => $builder, 'device' => [
                'last_seen_at' => $device['last_seen_at'] ?? null,
                'firmware_version' => $device['firmware_version'] ?? null,
                'firmware_reported_at' => $device['firmware_reported_at'] ?? null,
            ], 'csrf' => $this->csrf()]);
        });
    }

    public function sketch(int $id)
    {
        return $this->respond(function () use ($id) {
            // This authorizes the current tenant/unit even when the compiler is disabled.
            $this->builds->status($id);
            $files = [];
            foreach (BmgBuild::SOURCES as $name => $hash) {
                $path = ROOTPATH . 'firmware/bmg/compost/' . $name;
                $source = is_file($path) && !is_link($path) ? file_get_contents($path) : false;
                if (!is_string($source) || !hash_equals($hash, hash('sha256', $source))) {
                    throw new RuntimeException('Reviewed source unavailable.', 503);
                }
                $files[$name] = $source;
            }
            return $this->response->setJSON(['source' => $files['compost.ino'], 'filename' => 'compost.ino',
                'support_files' => ['commissioning_policy.h' => $files['commissioning_policy.h']],
                'motor_outputs_enabled' => false, 'csrf' => $this->csrf()]);
        });
    }

    public function build(int $id)
    {
        if (strtoupper($this->request->getMethod()) !== 'POST') {
            return $this->response->setStatusCode(405)->setHeader('Allow', 'POST')
                ->setHeader('Cache-Control', 'private, no-store')->setJSON(['message' => 'Method not allowed.']);
        }
        return $this->respond(function () use ($id) {
            $length = $this->request->getHeaderLine('Content-Length');
            if ($length !== '' && (!ctype_digit($length) || (float) $length > 32768)) {
                throw new InvalidArgumentException('Build input exceeds the allowed size.');
            }
            if (str_contains(strtolower($this->request->getHeaderLine('Content-Type')), 'application/json')) {
                $body = $this->request->getBody();
                if (!is_string($body) || strlen($body) > 32768) { throw new InvalidArgumentException('Invalid build input.'); }
                try { $data = json_decode($body, true, 16, JSON_THROW_ON_ERROR); }
                catch (Throwable) { throw new InvalidArgumentException('Invalid build input.'); }
            } else { $data = $this->request->getPost(); }
            if (!is_array($data) || array_is_list($data) || $this->request->getFiles() !== []) {
                throw new InvalidArgumentException('A configuration object without source uploads is required.');
            }
            unset($data[csrf_token()]);
            foreach (['allow_http', 'physical_isolation'] as $key) {
                if (!array_key_exists($key, $data) || !in_array($data[$key], [true, false, '1', '0'], true)) {
                    throw new InvalidArgumentException('Explicit build consent fields are required.');
                }
                $data[$key] = in_array($data[$key], [true, '1'], true);
            }
            // The service enforces its own 180-second compile deadline and kills its child tree.
            set_time_limit(240);
            return $this->response->setJSON($this->builds->build($id, $data) + ['csrf' => $this->csrf()]);
        });
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
                422 => 'Invalid USB setup input. Check the Wi-Fi password, reachable server URL, current device token and required consent fields.', 403 => 'USB workspace access denied.',
                404 => 'Unit or approved firmware not found.',
                409 => 'Setup conditions changed or are not satisfied. Use maintenance mode, a disabled schedule, the current bound-device token and no pending commands; reload the unit before retrying.',
                default => 'Firmware build or USB authorization is unavailable. Check the local compiler prerequisites and reload the workspace.',
            };
            return $this->response->setStatusCode($status)->setJSON(['success' => false, 'message' => $message, 'csrf' => $this->csrf()]);
        }
    }
}
