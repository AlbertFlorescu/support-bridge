<?php

declare(strict_types=1);

use Bridge\Runtime\VendorCredentialImporter;

require dirname(__DIR__) . '/bootstrap.php';

try {
    if (count($argv) !== 1) {
        throw new RuntimeException('The credential importer accepts JSON on standard input only.');
    }
    $metadata = (new VendorCredentialImporter())->run(STDIN);
    echo json_encode($metadata, JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable) {
    fwrite(STDERR, "Vendor credential import failed. Check the live opt-in, input format and private volume.\n");
    exit(1);
}
