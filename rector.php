<?php

/** @noinspection PhpUndefinedNamespaceInspection, PhpUndefinedClassInspection, PhpUndefinedConstantInspection */

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php73\Rector\ConstFetch\SensitiveConstantNameRector;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Rector\Php80\Rector\FunctionLike\MixedTypeRector;
use Rector\Php81\Rector\ClassMethod\NewInInitializerRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . "/src",
    ])->withPhpSets()->withSkip([
        SensitiveConstantNameRector::class,
        ClassPropertyAssignToConstructorPromotionRector::class,
        NewInInitializerRector::class,
        MixedTypeRector::class
    ])->withTypeCoverageLevel(0);
