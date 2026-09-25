<?php

namespace App\Platform\Rules;

/**
 * The value of one rule for one chain, and how it was reached.
 */
final readonly class ResolvedRule
{
    /**
     * @param  array<string, mixed>  $constraints  Combined bounds from ancestors.
     * @param  list<array<string, mixed>>  $trace  One entry per level visited (for explain()).
     */
    public function __construct(
        public string $key,
        public mixed $value,
        /** null = rule default */
        public ?string $sourceLevel,
        public ?string $sourceScopeId,
        public ?string $sourceName,
        /** Level that locked the value, when it is above the target. */
        public ?string $lockedByLevel,
        public ?string $lockedByScopeId,
        public ?string $lockedByName,
        public bool $lockedHere,
        public array $constraints,
        /** True when a stored value broke a constraint and a fallback was used. */
        public bool $fellBack,
        public array $trace,
    ) {}

    public function isLockedByAncestor(): bool
    {
        return $this->lockedByLevel !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(...$data);
    }
}
