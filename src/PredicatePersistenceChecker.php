<?php

/** @noinspection PhpInternalEntityUsedInspection */

namespace Sabatier\CoreData;

use Sabatier\Foundation\Predicates\Expression;
use Sabatier\Foundation\Predicates\ExpressionOperator;
use Sabatier\Foundation\Predicates\PredicateVisitorFlags;

/** @internal */
final class PredicatePersistenceChecker
{
    private SQLPredicateAnalyser $analyser {
        get {
            if (!isset($this->analyser)) {
                $this->analyser = new SQLPredicateAnalyser();
                $this->expression->accept($this->analyser, PredicateVisitorFlags::all);
            }
            return $this->analyser;
        }
    }
    private(set) bool $usesKVC {
        get => $this->usesKVC ??= $this->analyser->keyPathExpressions->contains(fn(Expression $expression): bool => str_contains($expression->predicateFormat, "."));
    }
    private(set) bool $usesKVO {
        get => $this->usesKVO ??= $this->analyser->keyPathExpressions->contains(fn(Expression $expression): bool => str_contains($expression->predicateFormat, "@"));
    }
    private(set) bool $isDeterministic {
        get => $this->isDeterministic ??= !$this->analyser->functionExpressions->contains(fn(Expression $expression): bool => $expression->operand instanceof ExpressionOperator && !$expression->operand->isDeterministic);
    }
    private(set) bool $isRuntimeOnly {
        get => $this->isRuntimeOnly ??= !$this->analyser->variableExpressions->isEmpty || !$this->analyser->subqueryExpressions->isEmpty || !$this->analyser->blockExpressions->isEmpty || $this->usesKVC || $this->usesKVO || !$this->isDeterministic;
    }

    public function __construct(private readonly Expression $expression)
    {
    }
}
