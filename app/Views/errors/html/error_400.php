<?php

$errorMsg = (ENVIRONMENT !== 'production' && isset($message))
    ? (string) $message
    : lang('Errors.sorryBadRequest');

echo \App\Services\ErrorPageRenderer::render(400, $errorMsg, [
    'title'   => lang('Errors.badRequest'),
    'heading' => 'Invalid request',
]);
