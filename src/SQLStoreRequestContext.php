<?php

namespace Sabatier\CoreData;

use Exception;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Number;
use Sabatier\Foundation\ObjectClass;
use function Sabatier\Foundation\request_concrete_implementation;

/**
 * @property int $debugLogLevel
 * @property bool $useColoredLogging
 * @internal
 */
abstract class SQLStoreRequestContext extends ObjectClass
{
    public SQLConnection $connection;
    public Number $transactionID;
    public ?QueryGenerationToken $queryGenerationToken = null;
    public readonly bool $shouldRegisterQueryGeneration;
    public bool $isWritingRequest = false;
    public bool $hasHistoryTracking = false;
    public readonly SQLGenerator $generator;
    public mixed $result;

    public function __construct(public readonly PersistentStoreRequest $persistentStoreRequest, public readonly ManagedObjectContext $context, public readonly SQLCore $sqlCore)
    {
        $this->result = new ArrayClass();
        $this->shouldRegisterQueryGeneration = false;
        $this->generator = new SQLGenerator($this);
    }

    public function __get(string $name)
    {
        return match ($name) {
            "debugLogLevel" => SQLCore::$debugDefault,
            "useColoredLogging" => SQLCore::$coloredLoggingDefault,
            default => $this->valueForUndefinedKey($name)
        };
    }

    public function __set(string $name, mixed $value): void
    {
        if ($name === "debugLogLevel") {
            SQLCore::$debugDefault = $value;
        } elseif ($name === "useColoredLogging") {
            SQLCore::$coloredLoggingDefault = $value;
        } else {
            $this->setValueForUndefinedKey($value, $name);
        }
    }

    /**
     * @throws Exception
     */
    public function executeEpilogue(): void
    {
    }

    /**
     * @throws Exception
     */
    public function executeRequestUsingConnection(SQLConnection $connection): bool
    {
        $this->connection = $connection;
        $this->connection->connect();
        $this->executePrologue();
        $ok = $this->executeRequestCore();
        if ($ok) {
            $this->executeEpilogue();
        }
        return $ok;
    }

    /**
     * @throws Exception
     */
    public function executeRequestCore(): bool
    {
        request_concrete_implementation($this, __FUNCTION__);
    }

    /**
     * @throws Exception
     */
    public function executePrologue(): void
    {
    }
}
