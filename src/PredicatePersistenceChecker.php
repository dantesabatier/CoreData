<?php

/** @noinspection PhpInternalEntityUsedInspection */

namespace Sabatier\CoreData;

use Sabatier\Foundation\Predicates\Expression;
use Sabatier\Foundation\Predicates\ExpressionOperator;
use Sabatier\Foundation\Predicates\PredicateVisitorFlags;

/** @internal */
final readonly class PredicatePersistenceChecker
{
    public bool $isRuntimeOnly;

    public function __construct(Expression $expression)
    {
        $analyser = new SQLPredicateAnalyser();
        $expression->accept($analyser, PredicateVisitorFlags::all);
        $this->isRuntimeOnly = !$analyser->variableExpressions->isEmpty || !$analyser->subqueryExpressions->isEmpty || $analyser->keyPathExpressions->contains(fn(Expression $expr): bool => str_contains($expr->keyPath, ".") || str_contains($expr->keyPath, "@")) || $analyser->functionExpressions->contains(fn(Expression $expression): bool => $expression->operand instanceof ExpressionOperator && !$expression->operand->isDeterministic);
    }
}
