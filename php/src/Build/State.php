<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Build;

use MapCova\GraphBuilder\Support\Json;

/** The state file of a build (`state.json`): the phase, the cursor inside it, and what every launch did. */
final class State
{
    public const PHASES = ['ways', 'needed', 'nodes', 'graph', 'contract', 'finish', 'done'];

    public string $phase = 'ways';
    /** Identifies the input, profile and zone this work directory was started for. */
    public string $key = '';
    public int $osmTimestamp = 0;
    // phase ways: offset of the next blob, bytes written to the way and restriction files, counts
    public int $waysOffset = 0;
    public int $waysSize = 0;
    public int $restrictionsSize = 0;
    public int $ways = 0;
    public int $restrictions = 0;
    /** Id of the last way kept: ways must come in rising order, also across launches. */
    public int $lastWayId = -1;
    // phase needed
    public int $needed = 0;
    // phase nodes: offset of the next blob, position in the needed ids, bytes written, whether node blocks were met
    public int $nodesOffset = 0;
    public int $neededIndex = 0;
    public int $nodesSize = 0;
    public bool $seenNodes = false;
    // phase contract: node count, contracted so far
    public int $nn = -1;
    public int $rank = 0;
    /** @var list<array{seconds:float,peak_bytes:int,phases:list<string>}> one entry per finished launch */
    public array $slices = [];

    public function next(): void
    {
        $i = array_search($this->phase, self::PHASES, true);
        $this->phase = self::PHASES[($i === false ? 0 : $i) + 1] ?? 'done';
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'phase' => $this->phase,
            'key' => $this->key,
            'osmTimestamp' => $this->osmTimestamp,
            'waysOffset' => $this->waysOffset,
            'waysSize' => $this->waysSize,
            'restrictionsSize' => $this->restrictionsSize,
            'ways' => $this->ways,
            'restrictions' => $this->restrictions,
            'lastWayId' => $this->lastWayId,
            'needed' => $this->needed,
            'nodesOffset' => $this->nodesOffset,
            'neededIndex' => $this->neededIndex,
            'nodesSize' => $this->nodesSize,
            'seenNodes' => $this->seenNodes,
            'nn' => $this->nn,
            'rank' => $this->rank,
            'slices' => $this->slices,
        ];
    }

    /** @param array<mixed> $a */
    public static function fromArray(array $a): self
    {
        $s = new self();
        $s->phase = Json::string($a, 'phase');
        $s->key = Json::string($a, 'key');
        $s->osmTimestamp = Json::int($a, 'osmTimestamp', 0);
        $s->waysOffset = Json::int($a, 'waysOffset', 0);
        $s->waysSize = Json::int($a, 'waysSize', 0);
        $s->restrictionsSize = Json::int($a, 'restrictionsSize', 0);
        $s->ways = Json::int($a, 'ways', 0);
        $s->restrictions = Json::int($a, 'restrictions', 0);
        $s->lastWayId = Json::int($a, 'lastWayId', -1);
        $s->needed = Json::int($a, 'needed', 0);
        $s->nodesOffset = Json::int($a, 'nodesOffset', 0);
        $s->neededIndex = Json::int($a, 'neededIndex', 0);
        $s->nodesSize = Json::int($a, 'nodesSize', 0);
        $s->seenNodes = Json::bool($a, 'seenNodes', false);
        $s->nn = Json::int($a, 'nn', -1);
        $s->rank = Json::int($a, 'rank', 0);
        foreach (Json::arr($a, 'slices') as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $phases = [];
            foreach (Json::arr($row, 'phases') as $p) {
                if (\is_string($p)) {
                    $phases[] = $p;
                }
            }
            $secs = $row['seconds'] ?? 0;
            $s->slices[] = [
                'seconds' => \is_int($secs) || \is_float($secs) ? (float) $secs : 0.0,
                'peak_bytes' => Json::int($row, 'peak_bytes', 0),
                'phases' => $phases,
            ];
        }

        return $s;
    }
}
