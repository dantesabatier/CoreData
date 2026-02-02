<?php

namespace Sabatier\CoreData;

/** @var string A flag that indicates whether a store is treated as read-only or not. The default value is false. */
const ReadOnlyPersistentStoreOption = "ReadOnlyPersistentStoreOption";
/** @var string A flag that indicates whether an XML file should be validated with the DTD while opening. The default value is false. */
const ValidateXMLStoreOption = "ValidateXMLStoreOption";
/** @var string Options key that specifies the connection timeout for Core Data stores. The corresponding value is a number object that represents the duration in seconds that Core Data will wait while attempting to create a connection to a persistent store. If a connection is cannot be made within that timeframe, the operation is aborted and an error is returned. */
const PersistentStoreTimeoutOption = "PersistentStoreTimeoutOption";

const ModelURLOption = "ModelURLOption";
