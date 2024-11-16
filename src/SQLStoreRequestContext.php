<?php

namespace Sabatier\CoreData;

use Exception;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Number;
use Sabatier\Foundation\ObjectClass;
use function Sabatier\Foundation\request_concrete_implementation;

/** * @internal */
abstract class SQLStoreRequestContext extends ObjectClass
{
    public SQLConnection $connection;
    public Number $transactionID;
    public ?QueryGenerationToken $queryGenerationToken = null;
    private(set) bool $shouldRegisterQueryGeneration = false;
    public bool $isWritingRequest = false;
    public bool $hasHistoryTracking = false;
    public readonly SQLGenerator $generator;
    public mixed $result;
    public int $debugLogLevel {
        get => SQLCore::$debugDefault;
        set {
            SQLCore::$debugDefault = $value;
        }
    }
    public bool $useColoredLogging {
        get => SQLCore::$coloredLoggingDefault;
        set {
            SQLCore::$coloredLoggingDefault = $value;
        }
    }

    public function __construct(public readonly PersistentStoreRequest $persistentStoreRequest, public readonly ManagedObjectContext $context, public readonly SQLCore $sqlCore)
    {
        $this->result = new ArrayClass();
        $this->generator = new SQLGenerator($this);
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
