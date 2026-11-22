<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Support;

use SplMinHeap;

/**
 * A min-heap of integers. The builder packs a priority and an id into one integer key (`priority << bits | id`): keys
 * are unique, so the order in which they come out does not depend on the heap's tie-breaking.
 *
 * @extends SplMinHeap<int>
 */
final class IntMinHeap extends SplMinHeap
{
}
