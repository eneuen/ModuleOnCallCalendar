<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleOnCallCalendar\Lib;

use InvalidArgumentException;

/**
 * A change the editor refuses. $key is a translation key (Messages/*.php)
 * and $params fill its %placeholders%, so the module page can show the
 * reason in the admin's language. The message is the English key and
 * parameters, for logs.
 */
final class EditorException extends InvalidArgumentException
{
    /**
     * @param array<string, string|int> $params
     */
    public function __construct(public readonly string $key, public readonly array $params = [])
    {
        parent::__construct($key . ($params === [] ? '' : ' ' . json_encode($params, JSON_UNESCAPED_UNICODE)));
    }
}
