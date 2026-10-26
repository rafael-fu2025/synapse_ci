<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Services\Api\ApiContext;
use App\Services\PortalQueueService;
use CodeIgniter\HTTP\ResponseInterface;

/** Today's active personal queue tickets; the same snapshot the portal cards render. */
final class QueueApiController extends ApiV1Controller
{
    public function index(): ResponseInterface
    {
        return $this->run(function (): ResponseInterface {
            $service = new PortalQueueService(identity: static fn (): array => ApiContext::identity());
            return $this->ok(['data' => $service->snapshot()]);
        });
    }
}
