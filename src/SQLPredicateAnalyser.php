<?php

/** @noinspection PhpInternalEntityUsedInspection */

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 16/12/20
 * Time: 06:18
 */

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Predicates\Expression;
use Sabatier\Foundation\Predicates\Predicate;
use Sabatier\Foundation\Predicates\PredicateOperator;
use Sabatier\Foundation\Predicates\PredicateVisitor;

/** @internal */
class SQLPredicateAnalyser implements PredicateVisitor
{
    /** @var ArrayClass<Predicate> */
    public ArrayClass $allModifierPredicates;
    /** @var ArrayClass<Expression> */
    public ArrayClass $subqueries;
    /** @var ArrayClass<Expression> */
    public ArrayClass $setExpressions;

    #[Override]
    public function visitPredicate(Predicate $predicate): void
    {
    }

    #[Override]
    public function visitPredicateExpression(Expression $expression): void
    {
    }

    #[Override]
    public function visitPredicateOperator(PredicateOperator $operator): void
    {
    }
}
