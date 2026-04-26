<?php

declare(strict_types=1);

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 30/06/20
 * Time: 16:51
 */
namespace Sabatier\CoreData;

use Exception;
use Override;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Predicates\Expression;
use function Sabatier\Foundation\fatal_error;
use function Sabatier\Foundation\typeof;

/**
 * An expression that evaluates the result of a fetch request on a managed object context.
 */
final class FetchRequestExpression extends Expression
{
    /**
     * @param Expression $requestExpression The expression for the receiver's fetch request.
     * @param Expression $contextExpression The expression for the receiver's managed object context.
     * @param bool $isCountOnlyRequest Returns a Boolean value that indicates whether the receiver represents a count-only fetch request.
     */
    protected function __construct(public readonly Expression $requestExpression, public readonly Expression $contextExpression, public readonly bool $isCountOnlyRequest = false)
    {
        parent::__construct();
    }

    #[Override]
    public function withSubstitutionVariables(Dictionary $variables): Expression
    {
        /** @noinspection PhpInternalEntityUsedInspection */
        return new FetchRequestExpression($this->requestExpression->withSubstitutionVariables($variables), $this->contextExpression->withSubstitutionVariables($variables), $this->isCountOnlyRequest);
    }

    /**
     * @throws Exception
     */
    #[Override]
    public function expressionValue(mixed $object = null, ?Dictionary $context = null): mixed
    {
        $managedObjectContext = $this->contextExpression->expressionValue($object, $context);
        if (!$managedObjectContext instanceof ManagedObjectContext) {
            $managedObjectContext
                |> typeof(...)
                |> (fn(string $x): string => sprintf("%s expecting \"%s\" given \"%s\" given", $this->debugDescription, ManagedObjectContext::class, $x))
                |> fatal_error(...);
        }
        $fetchRequest = $this->requestExpression->expressionValue($object, $context);
        if (!$fetchRequest instanceof FetchRequest) {
            $fetchRequest
                |> typeof(...)
                |> (fn(string $x): string => sprintf("%s expecting \"%s\" given \"%s\" given", $this->debugDescription, FetchRequest::class, $x))
                |> fatal_error(...);
        }
        if ($this->isCountOnlyRequest) {
            return $managedObjectContext->count($fetchRequest);
        }
        return $managedObjectContext->fetch($fetchRequest);
    }
}
