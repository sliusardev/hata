<?php

namespace Hata\Support\Content;

/**
 * What ContentTypeGenerator did: the class it wrote, the files it changed, and the lines it could not place.
 */
final readonly class GeneratedContentType
{
    /**
     * @param  class-string  $class
     * @param  list<string>  $updated  Files that received their lines.
     * @param  list<array{file: string, lines: string}>  $manual  Lines to add by hand, with the file they belong to.
     */
    public function __construct(
        public string $key,
        public string $class,
        public string $classFile,
        public array $updated,
        public array $manual,
    ) {}
}
