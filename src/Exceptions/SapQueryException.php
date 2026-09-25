<?php

declare(strict_types=1);

namespace Phattarachai\SapProxy\Exceptions;

use Illuminate\Support\Str;
use RuntimeException;

/**
 * HANA rejected the SQL (the proxy answered 422). The message is HANA's own error text, clean
 * enough to show to whoever wrote the query; the SQL itself rides along in context().
 */
class SapQueryException extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $sql = null)
    {
        parent::__construct($message);
    }

    /**
     * @return array{sql?: string}
     */
    public function context(): array
    {
        return $this->sql === null ? [] : ['sql' => Str::limit($this->sql, 4000)];
    }
}
