<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Build;

use RuntimeException;

/** Another process holds the lock of the work directory: a launch is running there. */
final class BuilderBusy extends RuntimeException
{
}
