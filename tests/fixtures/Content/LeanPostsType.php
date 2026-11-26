<?php

namespace Tests\Fixtures\Content;

use Hata\Support\Content\Fields\Text;
use Hata\Support\Content\Types\PostsType;

/**
 * Test-only subclass of the kit's posts: the same key, one more field, and parts of the common form switched off
 * (no author or excerpt unless the test names others).
 */
class LeanPostsType extends PostsType
{
    /**
     * @param  list<string>  $off
     */
    public function __construct(private readonly array $off = ['author', 'excerpt']) {}

    public function fields(): array
    {
        return [...parent::fields(), Text::make('subtitle')];
    }

    protected function without(): array
    {
        return $this->off;
    }
}
