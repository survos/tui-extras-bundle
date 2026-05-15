<?php

declare(strict_types=1);

namespace Survos\TuiExtrasBundle\Highlighter;

/**
 * Syntax highlighter that emits ANSI-colored lines compatible with DetailPanelWidget.
 *
 * Fast path: delegates to bat/batcat if installed (200+ languages, themes).
 * Built-in: PHP via token_get_all(), JSON via stdlib + tiny tokenizer.
 * Fallback: raw lines for anything else.
 *
 * All returned strings may contain ANSI escape codes — compatible with the
 * TUI render contract ("Lines MAY contain ANSI escape sequences").
 */
final class SyntaxHighlighter
{
    /** @var array<string,string[]> path → highlighted lines cache */
    private array $cache = [];

    private ?string $bat = null;
    private bool $batChecked = false;

    private const PHP_KEYWORDS = [
        \T_ABSTRACT, \T_ARRAY, \T_AS,
        \T_BREAK,
        \T_CALLABLE, \T_CASE, \T_CATCH, \T_CLASS, \T_CLONE, \T_CONST, \T_CONTINUE,
        \T_DECLARE, \T_DEFAULT, \T_DO,
        \T_ECHO, \T_ELSE, \T_ELSEIF, \T_EMPTY, \T_EVAL, \T_EXIT, \T_EXTENDS,
        \T_FINAL, \T_FINALLY, \T_FN, \T_FOR, \T_FOREACH, \T_FUNCTION,
        \T_GLOBAL, \T_GOTO,
        \T_IF, \T_IMPLEMENTS, \T_INCLUDE, \T_INCLUDE_ONCE,
        \T_INSTANCEOF, \T_INSTEADOF, \T_INTERFACE, \T_ISSET,
        \T_LIST,
        \T_NAMESPACE, \T_NEW,
        \T_PRINT, \T_PRIVATE, \T_PROTECTED, \T_PUBLIC,
        \T_REQUIRE, \T_REQUIRE_ONCE, \T_RETURN,
        \T_STATIC, \T_SWITCH,
        \T_THROW, \T_TRAIT, \T_TRY,
        \T_UNSET, \T_USE,
        \T_VAR,
        \T_WHILE,
        \T_YIELD, \T_YIELD_FROM,
    ];

    /** @return string[] ANSI-colored lines */
    public function highlightFile(string $path, bool $raw = false): array
    {
        if ($raw) {
            return $this->rawLines($path);
        }

        $cacheKey = $path.'@'.filemtime($path);
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $ext = strtolower(pathinfo($path, \PATHINFO_EXTENSION));

        // Fast path: bat/batcat handles 200+ languages automatically
        $bat = $this->findBat();
        if (null !== $bat) {
            $lines = $this->batHighlight($bat, $path);
            if (null !== $lines) {
                return $this->cache[$cacheKey] = $lines;
            }
        }

        $content = file_get_contents($path);
        if (false === $content) {
            return ['(read error)'];
        }

        $lines = match ($ext) {
            'php'  => $this->highlightPhp($content),
            'json' => $this->highlightJson($content),
            default => explode("\n", $content),
        };

        return $this->cache[$cacheKey] = $lines;
    }

    /** @return string[] */
    public function highlightPhp(string $source): array
    {
        $keywords = array_flip(self::PHP_KEYWORDS);
        // Add PHP 8.x tokens that may not exist in all builds
        foreach ([\T_ENUM ?? 0, \T_READONLY ?? 0, \T_MATCH ?? 0] as $t) {
            if ($t) {
                $keywords[$t] = true;
            }
        }

        try {
            $tokens = token_get_all($source, \TOKEN_PARSE);
        } catch (\Throwable) {
            $tokens = token_get_all($source);
        }

        $lines = [''];

        foreach ($tokens as $token) {
            if (\is_string($token)) {
                // Single-char tokens: operators, braces, semicolons
                $this->appendToLines($lines, $token);
                continue;
            }

            [$type, $text] = $token;

            $styled = match (true) {
                isset($keywords[$type])
                    => $this->styleLines($text, '1;35'),   // bold magenta — keywords
                \T_VARIABLE === $type
                    => $this->styleLines($text, '36'),     // cyan — $variables
                \in_array($type, [\T_CONSTANT_ENCAPSED_STRING, \T_ENCAPSED_AND_WHITESPACE, \T_START_HEREDOC, \T_END_HEREDOC], true)
                    => $this->styleLines($text, '33'),     // yellow — strings
                \in_array($type, [\T_LNUMBER, \T_DNUMBER], true)
                    => $this->styleLines($text, '36'),     // cyan — numbers
                \in_array($type, [\T_COMMENT, \T_DOC_COMMENT], true)
                    => $this->styleLines($text, '2;32'),   // dim green — comments
                \T_ATTRIBUTE === $type
                    => $this->styleLines($text, '33'),     // yellow — #[Attribute]
                \in_array($type, [\T_STRING, \T_NAME_FULLY_QUALIFIED, \T_NAME_RELATIVE, \T_NAME_QUALIFIED], true)
                    => $this->styleLines($text, '37'),     // white — identifiers
                \T_WHITESPACE === $type || \T_OPEN_TAG === $type || \T_CLOSE_TAG === $type
                    => $text,
                default => $text,
            };

            $this->appendToLines($lines, $styled);
        }

        return $lines;
    }

    /** @return string[] */
    public function highlightJson(string $source): array
    {
        $data = json_decode($source, true, 512, \JSON_BIGINT_AS_STRING);
        if (\JSON_ERROR_NONE !== json_last_error()) {
            // Malformed — show raw with a header
            return array_merge(['(invalid JSON — showing raw)', ''], explode("\n", $source));
        }

        $pretty = json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if (false === $pretty) {
            return explode("\n", $source);
        }

        return array_map([$this, 'colorizeJsonLine'], explode("\n", $pretty));
    }

    private function colorizeJsonLine(string $line): string
    {
        // Preserve leading whitespace
        $trimmed = ltrim($line);
        $indent  = substr($line, 0, \strlen($line) - \strlen($trimmed));

        if ('' === $trimmed) {
            return $line;
        }

        // Key: value  →  "key" in bold white, value colored
        if (preg_match('/^("(?:[^"\\\\]|\\\\.)*")\s*:\s*(.*)$/', $trimmed, $m)) {
            return $indent.$this->ansi($m[1], '1;37').': '.$this->colorizeJsonValue(rtrim($m[2], ',')).$this->trailingComma($m[2]);
        }

        // Pure structural or value-only lines
        return $indent.$this->colorizeJsonValue(rtrim($trimmed, ',')).$this->trailingComma($trimmed);
    }

    private function colorizeJsonValue(string $val): string
    {
        $val = trim($val);
        return match (true) {
            '' === $val || \in_array($val, ['{', '}', '[', ']', '{,', '},', '[,', '],'], true)
                => $val,                                    // structural — no color
            '"' === ($val[0] ?? '')
                => $this->ansi($val, '33'),                // yellow — string
            \in_array($val, ['true', 'false', 'null'], true)
                => $this->ansi($val, '35'),                // magenta — literal
            is_numeric($val)
                => $this->ansi($val, '36'),                // cyan — number
            default => $val,
        };
    }

    private function trailingComma(string $raw): string
    {
        return str_ends_with(rtrim($raw), ',') ? ',' : '';
    }

    // ── Internal helpers ─────────────────────────────────────────────────────

    /**
     * Apply an ANSI code to each line of $text individually.
     * Multi-line tokens (doc comments, heredocs) must not have codes spanning newlines.
     */
    private function styleLines(string $text, string $code): string
    {
        if (!str_contains($text, "\n")) {
            return $this->ansi($text, $code);
        }

        return implode("\n", array_map(
            fn (string $line) => '' !== $line ? $this->ansi($line, $code) : '',
            explode("\n", $text),
        ));
    }

    /**
     * Append potentially-multi-line styled text to the lines array in-place.
     * @param string[] $lines
     */
    private function appendToLines(array &$lines, string $text): void
    {
        $parts = explode("\n", $text);
        $lines[\array_key_last($lines)] .= array_shift($parts);
        foreach ($parts as $part) {
            $lines[] = $part;
        }
    }

    private function ansi(string $text, string $code): string
    {
        return '' !== $text ? "\e[{$code}m{$text}\e[0m" : '';
    }

    /**
     * Fix headings rendered by MarkdownWidget when called without widget-tree attachment.
     *
     * MarkdownWidget::renderHeading() uses resolveElement('heading') which returns a blank
     * Style when the widget has no WidgetContext. The heading text still gets the '## ' prefix
     * but no ANSI styling. This post-processor strips the prefix and applies our own bold/color.
     *
     * @param string[] $lines
     * @return string[]
     */
    public static function fixHeadings(array $lines): array
    {
        return array_map(static function (string $line): string {
            if (!preg_match('/^(#{1,6}) (.+)$/', $line, $m)) {
                return $line;
            }
            $level = \strlen($m[1]);
            $text  = $m[2];
            // h1: bold yellow underline, h2: bold cyan, h3+: bold white
            $code = match (true) {
                1 === $level => '1;4;33',  // bold + underline + yellow
                2 === $level => '1;36',    // bold cyan
                default      => '1;37',    // bold white
            };

            return "\e[{$code}m{$text}\e[0m";
        }, $lines);
    }

    /** @return string[]|null null if bat unavailable or fails */
    private function batHighlight(string $bat, string $path): ?array
    {
        $cmd = \sprintf(
            '%s --plain --color=always --wrap=never %s 2>/dev/null',
            $bat,
            escapeshellarg($path),
        );
        $output = shell_exec($cmd);

        return null !== $output && '' !== $output
            ? explode("\n", rtrim($output, "\n"))
            : null;
    }

    private function findBat(): ?string
    {
        if ($this->batChecked) {
            return $this->bat;
        }
        $this->batChecked = true;
        foreach (['bat', 'batcat'] as $cmd) {
            $path = trim((string) shell_exec("which {$cmd} 2>/dev/null"));
            if ('' !== $path) {
                $this->bat = $path;
                break;
            }
        }

        return $this->bat;
    }

    /** @return string[] */
    private function rawLines(string $path): array
    {
        $content = file_get_contents($path);

        return false !== $content ? explode("\n", $content) : ['(read error)'];
    }
}
