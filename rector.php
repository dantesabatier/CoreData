<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Class_\ConvertStaticToSelfRector;
use Rector\CodeQuality\Rector\ClassMethod\ExplicitReturnNullRector;
use Rector\CodeQuality\Rector\ClassMethod\LocallyCalledStaticMethodToNonStaticRector;
use Rector\CodeQuality\Rector\Identical\FlipTypeControlToUseExclusiveTypeRector;
use Rector\CodeQuality\Rector\If_\ObjectExplicitBoolCompareRector;
use Rector\CodeQuality\Rector\Isset_\IssetOnPropertyObjectToPropertyExistsRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveEmptyClassMethodRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveMixedDocblockOverruledByNativeTypeRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPrivateMethodParameterRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPrivateMethodRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPublicMethodParameterRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUselessParamTagRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUselessReturnTagRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUselessUnionReturnDocblockRector;
use Rector\DeadCode\Rector\If_\RemoveAlwaysTrueIfConditionRector;
use Rector\DeadCode\Rector\Node\RemoveNonExistingVarAnnotationRector;
use Rector\DeadCode\Rector\Property\RemoveDefaultValueFromAssignedPropertyRector;
use Rector\DeadCode\Rector\Property\RemoveUnusedPrivatePropertyRector;
use Rector\DeadCode\Rector\Property\RemoveUselessVarTagRector;
use Rector\DeadCode\Rector\StmtsAwareInterface\RemoveDeadInstanceOfAssertRector;
use Rector\Exception\Configuration\InvalidConfigurationException;
use Rector\Php73\Rector\ConstFetch\SensitiveConstantNameRector;
use Rector\Php74\Rector\Property\RestoreDefaultNullToNullableTypePropertyRector;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Rector\Php81\Rector\Property\ReadOnlyPropertyRector;

try {
    return RectorConfig::configure()
        ->withPaths([
            __DIR__ . "/src",
        ])->withPhpSets()->withSkip([
            SensitiveConstantNameRector::class,
            ClassPropertyAssignToConstructorPromotionRector::class,
            FlipTypeControlToUseExclusiveTypeRector::class,
            LocallyCalledStaticMethodToNonStaticRector::class,
            RemoveUnusedPrivateMethodRector::class,
            RemoveUnusedPrivateMethodParameterRector::class,
            RemoveUselessReturnTagRector::class,
            RemoveUselessParamTagRector::class,
            ExplicitReturnNullRector::class,
            RemoveAlwaysTrueIfConditionRector::class => [
                __DIR__ . "/src/SQLGenerator.php",
                __DIR__ . "/src/SQLAdapter.php"
            ],
            RemoveNonExistingVarAnnotationRector::class => [
                __DIR__ . "/src/ManagedObjectModel.php",
                __DIR__ . "/src/MigrationManager.php",
                __DIR__ . "/src/SQLGenerator.php",
            ],
            RestoreDefaultNullToNullableTypePropertyRector::class,
            ReadOnlyPropertyRector::class,
            RemoveUnusedPrivatePropertyRector::class => [
                __DIR__ . "/src/BatchFaultingArray.php",
                __DIR__ . "/src/SQLAttribute.php",
            ],
            IssetOnPropertyObjectToPropertyExistsRector::class,
            RemoveEmptyClassMethodRector::class,
            RemoveUnusedPublicMethodParameterRector::class,
            RemoveMixedDocblockOverruledByNativeTypeRector::class,
            RemoveUselessUnionReturnDocblockRector::class,
            RemoveDeadInstanceOfAssertRector::class,
            RemoveDefaultValueFromAssignedPropertyRector::class,
            RemoveNonExistingVarAnnotationRector::class,
            RemoveUselessVarTagRector::class => [
                __DIR__ . "/src/SQLPersistentHistoryChangeRequestContext.php",
            ],
            ConvertStaticToSelfRector::class => [
                __DIR__ . "/tests/SQLStoreMigratorCompositeDerivedTest.php",
                __DIR__ . "/tests/SQLStoreMigratorToOneRelationshipTest.php",
            ],
            ObjectExplicitBoolCompareRector::class
        ])->withPreparedSets(deadCode: true, codeQuality: true, earlyReturn: true);
} catch (InvalidConfigurationException $e) {
    error_log($e->getMessage());
}
