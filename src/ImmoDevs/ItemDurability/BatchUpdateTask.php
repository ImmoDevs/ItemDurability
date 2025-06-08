<?php

declare(strict_types=1);

namespace ImmoDevs\ItemDurability;

use pocketmine\scheduler\Task;

class BatchUpdateTask extends Task
{
    private ItemDurability $plugin;

    public function __construct(ItemDurability $plugin)
    {
        $this->plugin = $plugin;
    }

    public function onRun(): void
    {
        $this->plugin->processPendingUpdates();
    }
}