<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 21/06/20
 * Time: 14:10
 */

/** @var bool Temporarily used to test the functionality of the XML store. */
const SS_COREDATA_DEBUG_XML_STORE = false;
/** @var bool Temporarily used to bypass SQL Store foreign key checks, but we need to implement a save plan. */
const SS_COREDATA_DISABLE_FOREIGN_KEY_CHECKS = true;
/** @var bool There is a bug where the object graph can be truncated when using sort descriptors, this is because in order for {@see SQLFetchRequestContext::executeRequestCore()} to be able to add new leaves to the tree it needs to check the objectID column and with sort descriptors enabled this column may be retrieved in a different order. */
const SS_COREDATA_CAN_SAFELY_USE_SORT_DESCRIPTORS = false;
