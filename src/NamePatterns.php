<?php

declare(strict_types=1);

namespace ComposerStore;

/**
 * Package names to match, where `*` matches any characters. Matching is case-insensitive.
 */
final class NamePatterns
{
    /** @var list<string> */
    private readonly array $regexes;

    /**
     * @param list<string> $patterns
     */
    public function __construct(array $patterns)
    {
        $this->regexes = array_map(
            static fn (string $pattern): string
                => '{^' . str_replace('\*', '.*', preg_quote(strtolower($pattern))) . '$}',
            $patterns
        );
    }

    public function isEmpty(): bool
    {
        return $this->regexes === [];
    }

    public function matches(string $name): bool
    {
        $name = strtolower($name);
        foreach ($this->regexes as $regex) {
            if (preg_match($regex, $name) === 1) {
                return true;
            }
        }

        return false;
    }
}
