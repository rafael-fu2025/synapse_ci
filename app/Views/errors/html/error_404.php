<?php

$errorMsg = (ENVIRONMENT !== 'production' && isset($message))
    ? (string) $message
    : lang('Errors.sorryCannotFind');

echo \App\Services\ErrorPageRenderer::render(404, $errorMsg, [
    'title'   => lang('Errors.pageNotFound'),
    'heading' => 'Page not found',
]);
