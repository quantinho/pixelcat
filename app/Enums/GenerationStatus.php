<?php

namespace App\Enums;

/**
 * waiting and submitting are ours; the rest mirror Pixelforge.
 * Pixelforge snapshots may only move a generation to a higher rank.
 */
enum GenerationStatus: string
{
    case Waiting = 'waiting';
    case Submitting = 'submitting';
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function rank(): int
    {
        return match ($this) {
            self::Waiting => 0,
            self::Submitting => 1,
            self::Queued => 2,
            self::Processing => 3,
            self::Succeeded, self::Failed => 4,
        };
    }

    public function isFinal(): bool
    {
        return $this->rank() === 4;
    }

    public function label(): string
    {
        return match ($this) {
            self::Waiting => 'Waiting for a free slot',
            self::Submitting => 'Submitting',
            self::Queued => 'Queued',
            self::Processing => 'Generating',
            self::Succeeded => 'Done',
            self::Failed => 'Failed',
        };
    }

    /**
     * @return list<self>
     */
    public static function active(): array
    {
        return [self::Submitting, self::Queued, self::Processing];
    }
}
