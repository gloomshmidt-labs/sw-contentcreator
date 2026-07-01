<?php declare(strict_types=1);

namespace ContentCreator\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

class FillMissingContentTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'content_creator.fill_missing_content';
    }

    public static function getDefaultInterval(): int
    {
        return 86400; // täglich
    }
}
