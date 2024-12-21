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
    private(set) SQLConnection $connection;
    protected(set) Number $transactionID;
    protected(set) ?QueryGenerationToken $queryGenerationToken = null;
    protected(set) bool $shouldRegisterQueryGeneration = false;
    protected(set) bool $isWritingRequest = false;
    protected(set) bool $hasHistoryTracking = false;
    private(set) SQLGenerator $generator {
        get => $this->generator ??= new SQLGenerator($this);
    }
    protected(set) mixed $result {
        get => $this->result ??= new ArrayClass();
    }
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
    public SQLModel $sqlModel {
        get => $this->sqlCore->model;
    }

    public function __construct(public readonly PersistentStoreRequest $persistentStoreRequest, public readonly ManagedObjectContext $context, public readonly SQLCore $sqlCore)
    {
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
