<?php

/** @noinspection PhpInternalEntityUsedInspection */

namespace Sabatier\CoreData;

use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Predicates\Expression;
use Sabatier\Foundation\Predicates\ExpressionOperator;
use Sabatier\Foundation\Predicates\PredicateVisitorFlags;
use function Sabatier\Foundation\kvc_operator_from_key;

/** @internal */
final class DerivationSchemaCompatibility
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
    private(set) bool $usesKeyValueCoding {
        get => $this->usesKeyValueCoding ??= $this->analyser->keyPathExpressions->contains(fn(Expression $expression): bool => str_contains($expression->description, "."));
    }
    private(set) bool $usesKeyValueOperator {
        get => $this->usesKeyValueOperator ??= $this->analyser->keyPathExpressions->contains(fn(Expression $expression): bool => new ArrayClass(explode(".", $expression->description))->contains(fn(string $key): bool => kvc_operator_from_key($key) !== null));
    }
    private(set) bool $isDeterministic {
        get => $this->isDeterministic ??= !$this->analyser->functionExpressions->contains(fn(Expression $expression): bool => $expression->operand instanceof ExpressionOperator && !$expression->operand->isDeterministic);
    }
    private(set) bool $isRuntimeOnly {
        get => $this->isRuntimeOnly ??= !$this->analyser->variableExpressions->isEmpty || !$this->analyser->setExpressions->isEmpty || !$this->analyser->subqueryExpressions->isEmpty || !$this->analyser->blockExpressions->isEmpty || $this->usesKeyValueCoding || $this->usesKeyValueOperator || !$this->isDeterministic;
    }

    public function __construct(private readonly Expression $expression)
    {
    }
}
