<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\CLIRequest;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * BaseController provides a convenient place for loading components
 * and performing functions that are needed by all your controllers.
 *
 * Extend this class in any new controllers:
 * ```
 *     class Home extends BaseController
 * ```
 *
 * For security, be sure to declare any new methods as protected or private.
 */
abstract class BaseController extends Controller
{
    /**
     * Be sure to declare properties for any property fetch you initialized.
     * The creation of dynamic property is deprecated in PHP 8.2.
     */

    /**
     * Build a validated client hint for authoritative same-screen region reconciliation.
     * The ordinary redirect remains the no-JavaScript fallback.
     *
     * @param list<string> $regions
     *
     * @return array{mode: string, owner: string, regions: list<string>, url?: string}
     */
    protected function refreshRegions(string $owner, array $regions, ?string $url = null): array
    {
        if (!$this->validRefreshToken($owner)) {
            throw new \InvalidArgumentException('Invalid refresh owner.');
        }

        $regions = array_values(array_unique($regions));
        if ($regions === [] || array_filter($regions, fn ($region): bool => !is_string($region) || !$this->validRefreshToken($region)) !== []) {
            throw new \InvalidArgumentException('Invalid refresh regions.');
        }

        $descriptor = [
            'mode' => 'regions',
            'owner' => $owner,
            'regions' => $regions,
        ];
        if ($url !== null && $url !== '') { $descriptor['url'] = $url; }
        return $descriptor;
    }

    /** @return array{mode: string, url: string, history: string} */
    protected function refreshNavigation(string $url, string $history = 'push'): array
    {
        if (!in_array($history, ['push', 'replace'], true)) {
            throw new \InvalidArgumentException('Invalid refresh history mode.');
        }

        return ['mode' => 'navigate', 'url' => $url, 'history' => $history];
    }

    /** @return array{mode: string} */
    protected function refreshNone(): array
    {
        return ['mode' => 'none'];
    }

    private function validRefreshToken(string $value): bool
    {
        return preg_match('/^[a-z][a-z0-9._-]{0,63}$/D', $value) === 1;
    }

    /**
     * @param CLIRequest|IncomingRequest $request
     *
     * @return void
     */
    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        // Load here all helpers you want to be available in your controllers that extend BaseController.
        // Caution: Do not put the this below the parent::initController() call below.
        // $this->helpers = ['form', 'url'];

        // Caution: Do not edit this line.
        parent::initController($request, $response, $logger);

        // Preload any models, libraries, etc, here.
        // $this->session = service('session');
    }
}
