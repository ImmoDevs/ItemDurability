<?php

/**
 * MIT License
 *
 * Copyright (c) 2025 ImmoDevs
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 * Copyright is perpetual and does not expire.
 *
 * @auto-license
 */

declare(strict_types=1);

namespace ImmoDevs\ItemDurability;

use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerItemHeldEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerItemUseEvent;
use pocketmine\event\player\PlayerItemConsumeEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\item\Durable;
use pocketmine\item\Item;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\TextFormat;
use pocketmine\player\Player;
use pocketmine\scheduler\TaskHandler;

class ItemDurability extends PluginBase implements Listener
{
    private array $pendingUpdates = [];
    private array $lastUpdate = [];
    private ?TaskHandler $batchUpdateTask = null;
    
    private const DEFAULT_CONFIG = [
        'durability_format' => 'Durability: [%current%/%max%] (%percent%%)',
        'durability_color' => 'GREEN',
        'enable_low_durability_warning' => true,
        'low_durability_percentage' => 10,
        'low_durability_color' => 'RED',
        'update_interval_ticks' => 10,
        'throttle_seconds' => 0.2,
        'max_batch_size' => 50
    ];
    
    private const VALID_COLORS = [
        'BLACK', 'DARK_BLUE', 'DARK_GREEN', 'DARK_AQUA', 'DARK_RED', 
        'DARK_PURPLE', 'GOLD', 'GRAY', 'DARK_GRAY', 'BLUE', 'GREEN', 
        'AQUA', 'RED', 'LIGHT_PURPLE', 'YELLOW', 'WHITE'
    ];

    public function onEnable(): void
    {
        $this->saveDefaultConfig();
        $this->reloadConfig();
        
        if (!$this->validateConfig()) {
            $this->getLogger()->error("Invalid configuration detected. Plugin will use default values where necessary.");
        }
        
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->startBatchUpdateTask();
    }

    public function onDisable(): void
    {
        if ($this->batchUpdateTask !== null) {
            $this->batchUpdateTask->cancel();
        }
    }

    /**
     * Validates the plugin configuration
     */
    private function validateConfig(): bool
    {
        $config = $this->getConfig();
        $isValid = true;
        
        $format = $config->get('durability_format', '');
        if (empty($format) || !is_string($format)) {
            $this->getLogger()->warning("Invalid durability_format in config. Using default.");
            $config->set('durability_format', self::DEFAULT_CONFIG['durability_format']);
            $isValid = false;
        }
        
        $colors = ['durability_color', 'low_durability_color'];
        foreach ($colors as $colorKey) {
            $color = strtoupper($config->get($colorKey, ''));
            if (!in_array($color, self::VALID_COLORS)) {
                $this->getLogger()->warning("Invalid {$colorKey} in config. Using default.");
                $config->set($colorKey, self::DEFAULT_CONFIG[$colorKey]);
                $isValid = false;
            }
        }
        
        $percentage = $config->get('low_durability_percentage', 0);
        if (!is_numeric($percentage) || $percentage < 0 || $percentage > 100) {
            $this->getLogger()->warning("Invalid low_durability_percentage in config. Must be between 0-100.");
            $config->set('low_durability_percentage', self::DEFAULT_CONFIG['low_durability_percentage']);
            $isValid = false;
        }
        
        $updateInterval = $config->get('update_interval_ticks', 0);
        if (!is_numeric($updateInterval) || $updateInterval < 1) {
            $this->getLogger()->warning("Invalid update_interval_ticks in config. Must be at least 1.");
            $config->set('update_interval_ticks', self::DEFAULT_CONFIG['update_interval_ticks']);
            $isValid = false;
        }
        
        $throttle = $config->get('throttle_seconds', 0);
        if (!is_numeric($throttle) || $throttle < 0) {
            $this->getLogger()->warning("Invalid throttle_seconds in config. Must be 0 or greater.");
            $config->set('throttle_seconds', self::DEFAULT_CONFIG['throttle_seconds']);
            $isValid = false;
        }
        
        $batchSize = $config->get('max_batch_size', 0);
        if (!is_numeric($batchSize) || $batchSize < 1) {
            $this->getLogger()->warning("Invalid max_batch_size in config. Must be at least 1.");
            $config->set('max_batch_size', self::DEFAULT_CONFIG['max_batch_size']);
            $isValid = false;
        }
        
        $boolSettings = ['enable_low_durability_warning'];
        foreach ($boolSettings as $setting) {
            $value = $config->get($setting);
            if (!is_bool($value)) {
                $this->getLogger()->warning("Invalid {$setting} in config. Must be true or false.");
                $config->set($setting, self::DEFAULT_CONFIG[$setting]);
                $isValid = false;
            }
        }
        
        if (!$isValid) {
            $this->saveConfig();
        }
        
        return $isValid;
    }

    /**
     * Starts the batch update task
     */
    private function startBatchUpdateTask(): void
    {
        $updateInterval = $this->getConfig()->get('update_interval_ticks', self::DEFAULT_CONFIG['update_interval_ticks']);
        
        $this->batchUpdateTask = $this->getScheduler()->scheduleRepeatingTask(
            new BatchUpdateTask($this), 
            $updateInterval
        );
    }

    public function onPlayerJoin(PlayerJoinEvent $event): void
    {
        $this->queueDurabilityUpdate($event->getPlayer());
    }

    public function onPlayerQuit(PlayerQuitEvent $event): void
    {
        $playerName = $event->getPlayer()->getName();
        unset($this->pendingUpdates[$playerName]);
        unset($this->lastUpdate[$playerName]);
    }

    public function onItemHeld(PlayerItemHeldEvent $event): void
    {
        $this->queueDurabilityUpdate($event->getPlayer());
    }

    public function onInteract(PlayerInteractEvent $event): void
    {
        $this->queueDurabilityUpdate($event->getPlayer());
    }

    public function onBlockBreak(BlockBreakEvent $event): void
    {
        $this->queueDurabilityUpdate($event->getPlayer());
    }

    public function onEntityDamage(EntityDamageByEntityEvent $event): void
    {
        $damager = $event->getDamager();
        if ($damager instanceof Player) {
            $this->queueDurabilityUpdate($damager);
        }
    }

    public function onItemUse(PlayerItemUseEvent $event): void
    {
        $this->queueDurabilityUpdate($event->getPlayer());
    }

    public function onItemConsume(PlayerItemConsumeEvent $event): void
    {
        $this->queueDurabilityUpdate($event->getPlayer());
    }

    /**
     * Queues a player for durability update with throttling
     */
    private function queueDurabilityUpdate(Player $player): void
    {
        if (!$player->isOnline()) {
            return;
        }
        
        $playerName = $player->getName();
        $currentTime = microtime(true);
        $throttleTime = $this->getConfig()->get('throttle_seconds', self::DEFAULT_CONFIG['throttle_seconds']);
        
        if (isset($this->lastUpdate[$playerName]) && 
            $currentTime - $this->lastUpdate[$playerName] < $throttleTime) {
            return;
        }
        
        $item = $player->getInventory()->getItemInHand();
        if ($this->isDurableItem($item)) {
            $this->pendingUpdates[$playerName] = $currentTime;
            $this->lastUpdate[$playerName] = $currentTime;
        }
    }

    /**
     * Processes pending durability updates in batches
     */
    public function processPendingUpdates(): void
    {
        if (empty($this->pendingUpdates)) {
            return;
        }
        
        $maxBatchSize = $this->getConfig()->get('max_batch_size', self::DEFAULT_CONFIG['max_batch_size']);
        $processed = 0;
        
        foreach ($this->pendingUpdates as $playerName => $queueTime) {
            if ($processed >= $maxBatchSize) {
                break;
            }
            
            $player = $this->getServer()->getPlayerExact($playerName);
            if ($player === null || !$player->isOnline()) {
                unset($this->pendingUpdates[$playerName]);
                continue;
            }
            
            try {
                $this->updateItemDurability($player);
                unset($this->pendingUpdates[$playerName]);
                $processed++;
            } catch (\Exception $e) {
                $this->getLogger()->error("Error updating durability for {$playerName}: " . $e->getMessage());
                unset($this->pendingUpdates[$playerName]);
            }
        }
    }

    /**
     * Updates the durability display for a player's held item
     */
    private function updateItemDurability(Player $player): void
    {
        $inventory = $player->getInventory();
        $item = $inventory->getItemInHand();

        if ($this->isDurableItem($item)) {
            $this->updateItemLore($item, $player);
        }
    }

    /**
     * Checks if an item has valid durability
     */
    public function isDurableItem(Item $item): bool
    {
        return $item instanceof Durable && $item->getMaxDurability() > 0;
    }

    /**
     * Updates the lore of an item with durability information
     */
    public function updateItemLore(Item $item, Player $player): void
    {
        if (!$this->isDurableItem($item)) {
            return;
        }

        /** @var Durable $item */
        $maxDurability = $item->getMaxDurability();
        $currentDamage = $item->getDamage();
        $remainingDurability = $maxDurability - $currentDamage;
        
        $durabilityPercentage = ($remainingDurability / $maxDurability) * 100;
        
        $lore = $item->getLore();
        $filteredLore = [];
        
        foreach ($lore as $line) {
            if (strpos($line, "Durability:") === false) {
                $filteredLore[] = $line;
            }
        }

        $format = $this->getConfig()->get("durability_format", self::DEFAULT_CONFIG['durability_format']);
        $durabilityText = str_replace(
            ["%current%", "%max%", "%percent%"], 
            [$remainingDurability, $maxDurability, round($durabilityPercentage)], 
            $format
        );
        
        $color = $this->getDurabilityColor($durabilityPercentage);
        
        $enableLowDurabilityWarning = $this->getConfig()->get("enable_low_durability_warning", true);
        $lowDurabilityPercentage = $this->getConfig()->get("low_durability_percentage", 10);
        
        if ($enableLowDurabilityWarning && $durabilityPercentage <= $lowDurabilityPercentage) {
            $lowDurabilityColorName = $this->getConfig()->get("low_durability_color", "RED");
            $color = $this->getTextFormatColor($lowDurabilityColorName);
        }
        
        $filteredLore[] = $color . $durabilityText;
        
        $item->setLore($filteredLore);
        $inventory = $player->getInventory();
        $slot = $inventory->getHeldItemIndex();
        $inventory->setItem($slot, $item);
    }
    
    /**
     * Get TextFormat color constant from config string
     */
    private function getTextFormatColor(string $colorName): string
    {
        $colorName = strtoupper($colorName);
        $colors = [
            "BLACK" => TextFormat::BLACK,
            "DARK_BLUE" => TextFormat::DARK_BLUE,
            "DARK_GREEN" => TextFormat::DARK_GREEN,
            "DARK_AQUA" => TextFormat::DARK_AQUA,
            "DARK_RED" => TextFormat::DARK_RED,
            "DARK_PURPLE" => TextFormat::DARK_PURPLE,
            "GOLD" => TextFormat::GOLD,
            "GRAY" => TextFormat::GRAY,
            "DARK_GRAY" => TextFormat::DARK_GRAY,
            "BLUE" => TextFormat::BLUE,
            "GREEN" => TextFormat::GREEN,
            "AQUA" => TextFormat::AQUA,
            "RED" => TextFormat::RED,
            "LIGHT_PURPLE" => TextFormat::LIGHT_PURPLE,
            "YELLOW" => TextFormat::YELLOW,
            "WHITE" => TextFormat::WHITE
        ];
        
        return $colors[$colorName] ?? TextFormat::GREEN;
    }
    
    /**
     * Gets a color based on durability percentage with smooth gradation
     */
    private function getDurabilityColor(float $percentage): string
    {
        if ($percentage >= 80) {
            return TextFormat::GREEN;
        } elseif ($percentage >= 60) {
            return TextFormat::DARK_GREEN;
        } elseif ($percentage >= 40) {
            return TextFormat::YELLOW; 
        } elseif ($percentage >= 20) {
            return TextFormat::GOLD;
        } elseif ($percentage >= 10) {
            return TextFormat::RED;
        } else {
            return TextFormat::DARK_RED;
        }
    }
}