<?php
namespace Kanboard\Plugin\Agents\Model;

// Loads catalogue.json, the canonical WIP flag catalogue (Kanboard #4572 history points here).
// The kanboard:wip skill vendors a hash-checked copy; catalogue_version is the file's sha256.
class WipCatalogue
{
    const KEYS = ['stale', 'merged', 'donesubs', 'offboard', 'ended', 'mismatch', 'blocked', 'unverified', 'noowner', 'timemiss'];
    const APPLIES_TO = ['all', 'agents', 'unowned'];
    const THRESHOLDS = ['stale_days', 'unverified_hours', 'timemiss_hours', 'closed_lookback_days'];

    private array $flags = [];
    private array $thresholds = [];
    private string $version;

    public function __construct(?string $path = null)
    {
        $path = $path ?? __DIR__.'/../catalogue.json';
        $raw = is_file($path) ? file_get_contents($path) : false;
        if ($raw === false) {
            throw new \RuntimeException('WIP catalogue not found: '.$path);
        }
        $data = json_decode($raw, true);
        if (! is_array($data) || ($data['version'] ?? null) !== 1) {
            throw new \RuntimeException('WIP catalogue: missing or unsupported version');
        }
        foreach (self::THRESHOLDS as $name) {
            $v = $data['thresholds'][$name] ?? null;
            if (! is_int($v) || $v <= 0) {
                throw new \RuntimeException('WIP catalogue: threshold '.$name.' must be a positive integer');
            }
            $this->thresholds[$name] = $v;
        }
        foreach ($data['flags'] ?? [] as $f) {
            $this->flags[$this->validFlag($f)] = $f;
        }
        if (count($this->flags) !== count(self::KEYS) || count($data['flags']) !== count(self::KEYS)) {
            throw new \RuntimeException('WIP catalogue: expected exactly the flags '.implode(', ', self::KEYS));
        }
        $this->version = 'sha256:'.hash('sha256', $raw);
    }

    /** @return array<string, array> key => flag definition, in file order */
    public function flags(): array
    {
        return $this->flags;
    }

    public function flag(string $key): array
    {
        if (! isset($this->flags[$key])) {
            throw new \InvalidArgumentException('Unknown WIP flag '.$key);
        }
        return $this->flags[$key];
    }

    public function weight(string $key): int
    {
        return $this->flag($key)['weight'];
    }

    public function threshold(string $name): int
    {
        if (! isset($this->thresholds[$name])) {
            throw new \InvalidArgumentException('Unknown WIP threshold '.$name);
        }
        return $this->thresholds[$name];
    }

    public function version(): string
    {
        return $this->version;
    }

    /** @return string[] keys whose data does not exist yet (requires !== null), in file order */
    public function unavailable(): array
    {
        return array_values(array_keys(array_filter($this->flags, fn ($f) => $f['requires'] !== null)));
    }

    /** @return string[] keys that can be computed today */
    public function available(): array
    {
        return array_values(array_diff(array_keys($this->flags), $this->unavailable()));
    }

    private function validFlag($f): string
    {
        $ok = is_array($f)
            && in_array($f['key'] ?? null, self::KEYS, true)
            && is_string($f['label'] ?? null) && $f['label'] !== ''
            && is_int($f['weight'] ?? null) && $f['weight'] >= 1
            && is_string($f['fix'] ?? null) && $f['fix'] !== ''
            && is_bool($f['oneclick'] ?? null)
            && array_key_exists('requires', $f) && ($f['requires'] === null || is_string($f['requires']))
            && in_array($f['applies_to'] ?? null, self::APPLIES_TO, true);
        if (! $ok) {
            throw new \RuntimeException('WIP catalogue: malformed flag '.json_encode($f));
        }
        return $f['key'];
    }
}
