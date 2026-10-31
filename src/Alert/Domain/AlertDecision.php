<?php

declare(strict_types=1);

namespace Polaris\Alert\Domain;

enum AlertDecision
{
    /** The level is the one already known: nothing to write, nobody to tell. */
    case Nothing;

    /** The level becomes the known one, without telling anybody: a first assessment, or a level that went down. */
    case Baseline;

    /** The level got worse: the extension is told, and the new level is the known one. */
    case Notify;
}
