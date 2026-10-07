<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/logo_upload.php';

$path = tempnam(sys_get_temp_dir(), 'easyradar-logo-');
$errors = [];

function expect_logo_rejection(callable $callback, string $label): void
{
    global $errors;
    try {
        $callback();
        $errors[] = $label;
    } catch (RuntimeException) {
    }
}

try {
    file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aD1sAAAAASUVORK5CYII='));
    analyticspro_validate_logo_png($path, 'logo.png');
    analyticspro_validate_logo_png($path, 'LOGO.PNG');
    expect_logo_rejection(fn () => analyticspro_validate_logo_png($path, 'logo.php'), 'Reject non-PNG extension');
    expect_logo_rejection(fn () => analyticspro_save_uploaded_logo(['error' => UPLOAD_ERR_INI_SIZE]), 'Reject upload error');
    expect_logo_rejection(fn () => analyticspro_save_uploaded_logo(['error' => UPLOAD_ERR_OK, 'tmp_name' => $path, 'name' => 'logo.png']), 'Reject non-HTTP upload');
    expect_logo_rejection(fn () => analyticspro_save_uploaded_logo(['error' => UPLOAD_ERR_OK, 'tmp_name' => [], 'name' => []]), 'Reject malformed upload');
    file_put_contents($path, '<?php echo "not an image";');
    expect_logo_rejection(fn () => analyticspro_validate_logo_png($path, 'logo.png'), 'Reject disguised PHP');
    file_put_contents($path, "\x89PNG\r\n\x1a\n");
    expect_logo_rejection(fn () => analyticspro_validate_logo_png($path, 'logo.png'), 'Reject truncated PNG');
    file_put_contents($path, str_repeat('x', 2 * 1024 * 1024 + 1));
    expect_logo_rejection(fn () => analyticspro_validate_logo_png($path, 'logo.png'), 'Reject oversized upload');
    file_put_contents($path, '');
    expect_logo_rejection(fn () => analyticspro_validate_logo_png($path, 'logo.png'), 'Reject empty PNG');

    $source = file_get_contents(dirname(__DIR__) . '/admin/index.php');
    if (!str_contains($source, 'analyticspro_verify_csrf')
        || !str_contains($source, "require __DIR__ . '/_admin_check.php'")
        || !str_contains($source, 'enctype="multipart/form-data"')) {
        $errors[] = 'Admin upload must preserve authentication, CSRF and multipart form';
    }
} finally {
    unlink($path);
}

if ($errors !== []) {
    fwrite(STDERR, implode("\n", $errors) . "\n");
    exit(1);
}
echo "PASS: logo PNG validation and upload guards\n";
