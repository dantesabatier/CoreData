<?php

declare(strict_types=1);

require_once __DIR__ . "/vendor/autoload.php";

use Sabatier\CoreData\ManagedObject;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Bundle;
use Sabatier\Foundation\LocalizationExtractor;

try {
    $bundle = Bundle::bundleForClass(ManagedObject::class);
    $excludedFilenames = new ArrayClass(["SQLAdapter", "SQLBinaryIndex", "SQLGenerator", "SQLIndex", "SQLRTreeIndex"]);
    $extractor = new LocalizationExtractor($bundle, $bundle->localizations, $excludedFilenames);
    $extractor->extract();
} catch (Exception $exception) {
    error_log("Exception raised $exception");
}
