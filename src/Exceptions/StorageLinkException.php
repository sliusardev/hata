<?php

namespace Hata\Exceptions;

use RuntimeException;

/**
 * The `public/storage` symlink could not be created. The message is translated and tells how to create it by hand.
 */
class StorageLinkException extends RuntimeException {}
