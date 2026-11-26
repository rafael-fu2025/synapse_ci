<?php

echo \App\Services\ErrorPageRenderer::render(500, lang('Errors.weHitASnag'), [
    'title'   => lang('Errors.whoops'),
    'heading' => lang('Errors.whoops'),
    'icon'    => 'bi-exclamation-octagon-fill',
    'tone'    => 'maroon',
]);
