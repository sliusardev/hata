<?php

namespace Hata\Enums;

/**
 * What happened to one file of a move between disks.
 */
enum MoveOutcome: string
{
    case Moved = 'moved';

    /** The row was deleted meanwhile, or its file isn't on the disk. */
    case Missing = 'missing';

    /** A copy or the switch failed; the file stays where it was. */
    case Failed = 'failed';

    /** The file was cropped or replaced while it was being copied; it stays where it was. */
    case Changed = 'changed';
}
