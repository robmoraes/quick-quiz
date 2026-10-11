<?php

namespace App\Storage;

/** Bounded-memory object inventory, used by explicit catalog exports. */
interface IterableContentStorage extends ContentStorage
{
    /** @return iterable<string> */
    public function iterate(string $prefix): iterable;
}
