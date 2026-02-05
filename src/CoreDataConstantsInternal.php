<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 21/06/20
 * Time: 14:10
 */

namespace Sabatier\CoreData;

/** @var bool Temporarily used to test the functionality of the XML store. */
const SS_COREDATA_DEBUG_XML_STORE = false;
/** @var bool Temporarily used to bypass SQL Store foreign key checks, but we need to implement a save plan. */
const SS_COREDATA_DISABLE_FOREIGN_KEY_CHECKS = true;
const SS_COREDATA_USES_RELATIONSHIPS_SORT_DESCRIPTORS = true;
const ManagedObjectObjectIDKey = "objectID";
const ManagedObjectEntityNameKey = "entityName";
const ManagedObjectVersionKey = "version";
