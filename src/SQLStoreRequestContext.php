<?php

namespace Sabatier\CoreData;

use Exception;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Number;
use Sabatier\Foundation\ObjectClass;

/** @internal */
abstract class SQLStoreRequestContext extends ObjectClass
{
    protected(set) SQLConnection $connection;
    protected(set) Number $transactionID;
    protected(set) ?QueryGenerationToken $queryGenerationToken = null;
    protected(set) bool $shouldRegisterQueryGeneration = false;
    protected(set) SQLGenerator $generator {
        get => $this->generator ??= new SQLGenerator($this);
    }
    protected(set) mixed $result {
        get => $this->result ??= new ArrayClass();
    }
    public int $debugLogLevel {
        get => SQLCore::$debugDefault;
        set => SQLCore::$debugDefault = $value;
    }
    public bool $useColoredLogging {
        get => SQLCore::$coloredLoggingDefault;
        set => SQLCore::$coloredLoggingDefault = $value;
    }
    public SQLModel $sqlModel {
        get => $this->sqlCore->model;
    }
    public bool $isWritingRequest {
        get => false;
    }
    public bool $hasHistoryTracking {
        get => false;
    }

    public function __construct(public readonly PersistentStoreRequest $persistentStoreRequest, public readonly ManagedObjectContext $context, public readonly SQLCore $sqlCore)
    {
    }

    /**
     * @throws Exception
     */
    protected function executeEpilogue(): void
    {
    }

    /**
     * @throws Exception
     */
    abstract protected function executeRequestCore(): bool;

    /**
     * @throws Exception
     */
    protected function executePrologue(): void
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
}
