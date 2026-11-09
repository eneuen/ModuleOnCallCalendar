<?php

// SPDX-License-Identifier: GPL-3.0-or-later

/**
 * Fails (exit 1) if shipped module code uses a function or pattern the
 * security rules ban: shell or code execution and unserialize in PHP, and
 * eval or HTML injection in the browser script and views.
 *
 * PHP files are read with PHP's tokenizer, so names inside strings and
 * comments (such as SQL identifiers in backticks) are not mistaken for
 * calls. Development tools and tests are not shipped and are not checked.
 *
 * Usage, from the module folder: php tools/check-banned.php
 */

declare(strict_types=1);

const BANNED_FUNCTIONS = ['exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'pcntl_exec',
    'assert', 'create_function', 'unserialize', 'call_user_func', 'call_user_func_array'];
const PHP_DIRS = ['App', 'Lib', 'Models', 'Setup', 'Messages', 'agi-bin'];
const TEXT_RULES = [
    'public/assets/js/src' => [
        '/(^|[^\w.$])eval\s*\(/' => 'eval()',
        '/new\s+Function\s*\(/' => 'new Function()',
        '/set(Timeout|Interval)\s*\(\s*[\'"`]/' => 'setTimeout/setInterval with a string',
        '/\b(innerHTML|outerHTML|insertAdjacentHTML)\b|document\.write|\.html\(/' => 'HTML injection',
        '/\$\(\s*[\'"`]\s*</' => 'jQuery HTML construction',
    ],
    'App/Views' => [
        '/<script/i' => 'inline script',
        '/\son[a-z]+\s*=/i' => 'inline event handler',
    ],
];

$root = dirname(__DIR__);
$found = [];

foreach (PHP_DIRS as $dir) {
    foreach (files("$root/$dir", 'php') as $file) {
        $tokens = token_get_all((string)file_get_contents($file));
        $inBackticks = false;
        foreach ($tokens as $i => $token) {
            if ($token === '`') {
                $inBackticks = !$inBackticks;
                if ($inBackticks) {
                    $found[] = [$file, lineOf($tokens, $i), 'backtick shell execution'];
                }
            } elseif (is_array($token) && $token[0] === T_EVAL) {
                $found[] = [$file, $token[2], 'eval'];
            } elseif (is_array($token) && $token[0] === T_STRING && in_array(strtolower($token[1]), BANNED_FUNCTIONS, true)
                && nextIs($tokens, $i, '(') && !isMethodOrDeclaration($tokens, $i)) {
                $found[] = [$file, $token[2], $token[1] . '()'];
            }
        }
    }
}

foreach (TEXT_RULES as $dir => $rules) {
    foreach (files("$root/$dir", null) as $file) {
        foreach (file($file) ?: [] as $n => $line) {
            foreach ($rules as $pattern => $label) {
                if (preg_match($pattern, $line)) {
                    $found[] = [$file, $n + 1, $label];
                }
            }
        }
    }
}

foreach ($found as [$file, $line, $what]) {
    printf("%s:%d: banned %s\n", substr($file, strlen($root) + 1), $line, $what);
}
echo $found === [] ? "No banned functions found.\n" : count($found) . " banned use(s) found.\n";
exit($found === [] ? 0 : 1);

/** @return list<string> */
function files(string $dir, ?string $extension): array
{
    if (!is_dir($dir)) {
        return [];
    }
    $out = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
        if ($f->isFile() && ($extension === null || $f->getExtension() === $extension)) {
            $out[] = $f->getPathname();
        }
    }
    sort($out);
    return $out;
}

function nextIs(array $tokens, int $i, string $what): bool
{
    for ($j = $i + 1; isset($tokens[$j]); $j++) {
        if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        return $tokens[$j] === $what;
    }
    return false;
}

/** A method call ($pdo->exec(), Foo::exec()) or a function/method declaration, not the global function. */
function isMethodOrDeclaration(array $tokens, int $i): bool
{
    for ($j = $i - 1; $j >= 0; $j--) {
        if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        return is_array($tokens[$j]) && in_array($tokens[$j][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true);
    }
    return false;
}

function lineOf(array $tokens, int $i): int
{
    for ($j = $i; $j >= 0; $j--) {
        if (is_array($tokens[$j])) {
            return $tokens[$j][2] + substr_count($tokens[$j][1], "\n");
        }
    }
    return 1;
}
