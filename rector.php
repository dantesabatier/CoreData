<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\ClassMethod\ExplicitReturnNullRector;
use Rector\CodeQuality\Rector\ClassMethod\LocallyCalledStaticMethodToNonStaticRector;
use Rector\CodeQuality\Rector\Identical\FlipTypeControlToUseExclusiveTypeRector;
use Rector\CodeQuality\Rector\If_\CombineIfRector;
use Rector\CodeQuality\Rector\If_\ExplicitBoolCompareRector;
use Rector\CodeQuality\Rector\Isset_\IssetOnPropertyObjectToPropertyExistsRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPrivateMethodParameterRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPrivateMethodRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUselessParamTagRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUselessReturnTagRector;
use Rector\DeadCode\Rector\If_\RemoveAlwaysTrueIfConditionRector;
use Rector\DeadCode\Rector\Property\RemoveUnusedPrivatePropertyRector;
use Rector\EarlyReturn\Rector\Return_\ReturnBinaryOrToEarlyReturnRector;
use Rector\Exception\Configuration\InvalidConfigurationException;
use Rector\Php73\Rector\ConstFetch\SensitiveConstantNameRector;
use Rector\Php74\Rector\Property\RestoreDefaultNullToNullableTypePropertyRector;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Rector\Php81\Rector\Property\ReadOnlyPropertyRector;
use Rector\Strict\Rector\Empty_\DisallowedEmptyRuleFixerRector;

try {
    return RectorConfig::configure()
        ->withPaths([
            __DIR__ . "/src",
        ])->withPhpSets()->withSkip([
            SensitiveConstantNameRector::class,
            ClassPropertyAssignToConstructorPromotionRector::class,
            ExplicitBoolCompareRector::class,
            FlipTypeControlToUseExclusiveTypeRector::class,
            DisallowedEmptyRuleFixerRector::class,
            LocallyCalledStaticMethodToNonStaticRector::class,
            RemoveUnusedPrivateMethodRector::class,
            RemoveUnusedPrivateMethodParameterRector::class,
            RemoveUselessReturnTagRector::class,
            RemoveUselessParamTagRector::class,
            ExplicitReturnNullRector::class,
            CombineIfRector::class => [
                __DIR__ . "/src/SQLGenerator.php",
                __DIR__ . "/src/SQLStoreMigrator.php"
            ],
            RemoveAlwaysTrueIfConditionRector::class => [
                __DIR__ . "/src/SQLGenerator.php",
                __DIR__ . "/src/SQLAdapter.php"
            ],
            RestoreDefaultNullToNullableTypePropertyRector::class,
            ReadOnlyPropertyRector::class,
            RemoveUnusedPrivatePropertyRector::class => [
                __DIR__ . "/src/BatchFaultingArray.php",
                __DIR__ . "/src/SQLAttribute.php",
            ],
            ReturnBinaryOrToEarlyReturnRector::class => [
                __DIR__ . "/src/SQLGenerator.php",
            ],
            IssetOnPropertyObjectToPropertyExistsRector::class
        ])->withPreparedSets(deadCode: true, codeQuality: true, earlyReturn: true);
} catch (InvalidConfigurationException $e) {
    error_log($e->getMessage());
}
