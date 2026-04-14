<?php

/** @noinspection PhpInternalEntityUsedInspection */

namespace Sabatier\CoreData;

use Sabatier\Foundation\Predicates\Expression;
use Sabatier\Foundation\Predicates\ExpressionOperator;
use Sabatier\Foundation\Predicates\Predicate;
use Sabatier\Foundation\Predicates\PredicateOperatorType;
use Sabatier\Foundation\Predicates\PredicateVisitorFlags;
use function Sabatier\Foundation\components_from_key_path;
use function Sabatier\Foundation\kvc_components;
use function Sabatier\Foundation\kvc_operator_from_key;

/** @internal */
final class PredicateCacheAnalysis
{
    private SQLPredicateAnalyser $analyser {
        get {
            if (isset($this->analyser)) {
                return $this->analyser;
            }
            $this->analyser = new SQLPredicateAnalyser();
            $this->predicate->accept($this->analyser, PredicateVisitorFlags::all);
            return $this->analyser;
        }
    }
    private(set) bool $isDeterministic {
        get => $this->isDeterministic ??= !$this->analyser->functionExpressions->contains(fn(Expression $e): bool => $e->operand instanceof ExpressionOperator && !$e->operand->isDeterministic);
    }
    private(set) bool $hasVolatileOperators {
        get => $this->hasVolatileOperators ??= $this->analyser->allTypePredicates->contains(fn(PredicateOperatorType $operatorType): bool => match ($operatorType) {
            PredicateOperatorType::between,
            PredicateOperatorType::contains,
            PredicateOperatorType::beginsWith,
            PredicateOperatorType::endsWith,
            PredicateOperatorType::like,
            PredicateOperatorType::matches => true,
            default => false
        });
    }
    private(set) bool $usesDeepKeyPaths {
        get => $this->usesDeepKeyPaths ??= $this->analyser->keyPathExpressions->contains(fn(Expression $e): bool => components_from_key_path($e->description)->remainderPath !== null);
    }
    private(set) bool $usesKVCOperators {
        get => $this->usesKVCOperators ??= $this->analyser->keyPathExpressions->contains(fn(Expression $e): bool => kvc_operator_from_key(sprintf("@%s", kvc_components($e->description)[1])) !== null);
    }
    private(set) bool $hasRuntimeCollections {
        get => $this->hasRuntimeCollections ??= !$this->analyser->variableExpressions->isEmpty || !$this->analyser->setExpressions->isEmpty || !$this->analyser->subqueryExpressions->isEmpty || !$this->analyser->blockExpressions->isEmpty;
    }
    private(set) bool $isRuntimeOnly {
        get => $this->isRuntimeOnly ??= $this->hasRuntimeCollections || $this->usesDeepKeyPaths || $this->usesKVCOperators || $this->hasVolatileOperators || !$this->isDeterministic;
    }

    public function __construct(private readonly Predicate $predicate)
    {
    }
}
