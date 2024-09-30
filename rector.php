<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\If_\CombineIfRector;
use Rector\CodeQuality\Rector\If_\ExplicitBoolCompareRector;
use Rector\Config\RectorConfig;
use Rector\Exception\Configuration\InvalidConfigurationException;
use Rector\Php73\Rector\ConstFetch\SensitiveConstantNameRector;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Rector\Php80\Rector\FunctionLike\MixedTypeRector;
use Rector\Php81\Rector\ClassMethod\NewInInitializerRector;

try {
    return RectorConfig::configure()
        ->withPaths([
            __DIR__ . "/src",
        ])->withPhpSets()->withSkip([
            SensitiveConstantNameRector::class,
            ClassPropertyAssignToConstructorPromotionRector::class,
            NewInInitializerRector::class,
            MixedTypeRector::class,
            ExplicitBoolCompareRector::class,
            CombineIfRector::class => [
                "/src/SQLGenerator.php",
                "/src/SQLStoreMigrator.php"
            ]
        ])->withCodeQualityLevel(30);
} catch (InvalidConfigurationException $e) {
    error_log($e->getMessage());
}
