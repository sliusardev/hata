<?php

namespace Hata\Support\Install;

/**
 * What an edit of a host file did.
 */
enum HostFileStatus: string
{
    /** The file was edited. */
    case Changed = 'changed';

    /** The file already had the change; it was not written. */
    case AlreadyDone = 'already_done';

    /** The file does not have the shape the edit knows; it was left untouched and the lines are to be added by hand. */
    case Unrecognized = 'unrecognized';
}
