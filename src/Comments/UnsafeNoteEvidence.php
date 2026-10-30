<?php

declare(strict_types=1);

namespace Bridge\Comments;

use RuntimeException;

/** Destination evidence violates the internal-only contract; further appends must stop. */
final class UnsafeNoteEvidence extends RuntimeException
{
}
