<?php

/** @noinspection PhpInternalEntityUsedInspection */

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 16/12/20
 * Time: 06:18
 */

namespace Sabatier\CoreData;

use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Expression;
use Sabatier\Foundation\Predicate;
use Sabatier\Foundation\PredicateOperator;
use Sabatier\Foundation\PredicateVisitor;

/** @internal */
class SQLPredicateAnalyser implements PredicateVisitor
{
    /** @var ArrayClass<Predicate> */
    public ArrayClass $allModifierPredicates;
    /** @var ArrayClass<Expression> */
    public ArrayClass $subqueries;
    /** @var ArrayClass<Expression> */
    public ArrayClass $setExpressions;

    public function visitPredicate(Predicate $predicate): void
    {
    }

    public function visitPredicateExpression(Expression $expression): void
    {
    }

    public function visitPredicateOperator(PredicateOperator $operator): void
    {
    }
}
