<?php

/**
 * This file is part of laravel-rls, a Matchory application.
 *
 * Unauthorized copying of this file, via any medium, is strictly prohibited.
 * Its contents are strictly confidential and proprietary.
 *
 * @copyright 2020–2026 Matchory GmbH · All rights reserved
 * @author    Moritz Friedrich <moritz@matchory.com>
 */

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Exception;
use PHPUnit\Framework\ExpectationFailedException;
use stdClass;

trait WithTestingUtils
{
    /**
     * @throws Exception
     * @throws ExpectationFailedException
     */
    protected function selectSingleValueFromDatabase(
        string $query,
        string $column = 'value',
        ?string $connectionName = null,
    ): mixed {
        $connection = $connectionName !== null
            ? DB::connection($connectionName)
            : DB::connection();
        $result = $connection->selectOne($query);

        self::assertIsObject($result);
        self::assertInstanceOf(stdClass::class, $result);
        self::assertObjectHasProperty($column, $result);

        return $result->{$column} ?? null;
    }
}
