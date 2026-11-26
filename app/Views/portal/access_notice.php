<?= \App\Services\ErrorPageRenderer::render(
    403,
    'Your account is not authorized for the ' . (string) $portalTitle . ' workstation. The hostname does not grant department access. Use your authorized portal below—workstation hosts have separate sign-in sessions, so you may need to sign in there.',
    [
        'title'          => 'Workstation access restricted',
        'heading'        => 'This workstation is restricted',
        'primaryHref'    => (string) $destination,
        'primaryLabel'   => (string) $destinationLabel,
        'secondaryHref'  => (string) $mainUrl,
        'secondaryLabel' => 'Main personal portal',
    ]
) ?>
