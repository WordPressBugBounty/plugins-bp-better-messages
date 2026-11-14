<?php

declare(strict_types=1);

namespace WordPlusSDK\MaxMind\Db\Reader;

/**
 * This class should be thrown when unexpected data is found in the database.
 */
// phpcs:disable
class InvalidDatabaseException extends \Exception {}
