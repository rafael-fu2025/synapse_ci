<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Login carousel settings.
 *
 * The managed artwork store lives outside the web root so that the bytes are
 * only reachable through the authorised publishing route.
 */
final class Carousel extends BaseConfig
{
    /**
     * Private store root for managed login carousel artwork.
     *
     * Each tenant owns a scope directory beneath it; the reserved "default"
     * scope holds the permanent default image.
     */
    public string $storeRoot = WRITEPATH . 'uploads/carousel';
}
