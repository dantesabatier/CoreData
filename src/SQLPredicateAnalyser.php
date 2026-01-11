<?php

/** @noinspection PhpInternalEntityUsedInspection */

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Predicates\Expression;
use Sabatier\Foundation\Predicates\ExpressionType;
use Sabatier\Foundation\Predicates\Predicate;
use Sabatier\Foundation\Predicates\PredicateOperator;
use Sabatier\Foundation\Predicates\PredicateVisitor;

/** @internal */
final readonly class SQLPredicateAnalyser implements PredicateVisitor
{
    /** @var ArrayClass<Expression> */
    public ArrayClass $keyPathExpressions;
    /** @var ArrayClass<Expression> */
    public ArrayClass $functionExpressions;
    /** @var ArrayClass<Expression> */
    public ArrayClass $variableExpressions;
    /** @var ArrayClass<Expression> */
    public ArrayClass $aggregateExpressions;
    /** @var ArrayClass<Expression> */
    public ArrayClass $subqueryExpressions;
    /** @var ArrayClass<Expression> */
    public ArrayClass $conditionalExpressions;

    public function __construct()
    {
        $this->keyPathExpressions = new ArrayClass();
        $this->functionExpressions = new ArrayClass();
        $this->variableExpressions = new ArrayClass();
        $this->aggregateExpressions = new ArrayClass();
        $this->subqueryExpressions = new ArrayClass();
        $this->conditionalExpressions = new ArrayClass();
    }

    #[Override]
    public function visitPredicate(Predicate $predicate): void
    {
    }

    #[Override]
    public function visitPredicateExpression(Expression $expression): void
    {
        if ($array = match ($expression->expressionType) {
            ExpressionType::keyPath => $this->keyPathExpressions,
            ExpressionType::function => $this->functionExpressions,
            ExpressionType::variable => $this->variableExpressions,
            ExpressionType::aggregate => $this->aggregateExpressions,
            ExpressionType::subquery => $this->subqueryExpressions,
            ExpressionType::conditional => $this->conditionalExpressions,
            default => null
        }) {
            $array->append($expression);
        }
    }

    #[Override]
    public function visitPredicateOperator(PredicateOperator $operator): void
    {
    }
}
