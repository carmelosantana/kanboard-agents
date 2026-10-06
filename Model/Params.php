<?php
namespace Kanboard\Plugin\Agents\Model;

// Validates JSON-RPC / form params. Procedure params carry no PHP type hints (a TypeError is a
// fatal with no JSON-RPC body), so every value arrives untyped and is checked here.
final class Params
{
    /** A positive id from an int or a digit string; 0 when it is anything else. */
    public static function id($v): int
    {
        if (is_int($v)) {
            return $v > 0 ? $v : 0;
        }
        return is_string($v) && ctype_digit($v) ? (int) $v : 0;
    }

    /** Null stays null; otherwise a list of positive ids, or InvalidArgumentException. */
    public static function idList($v, string $name): ?array
    {
        if ($v === null) {
            return null;
        }
        if (! is_array($v)) {
            throw new \InvalidArgumentException($name.' must be an array of ids');
        }
        $out = [];
        foreach ($v as $item) {
            $id = self::id($item);
            if ($id === 0) {
                throw new \InvalidArgumentException($name.' must be an array of ids');
            }
            $out[$id] = $id;
        }
        return array_values($out);
    }

    public static function flag($v, string $name): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if ($v === 0 || $v === 1 || $v === '0' || $v === '1') {
            return (bool) (int) $v;
        }
        throw new \InvalidArgumentException($name.' must be a boolean');
    }
}
