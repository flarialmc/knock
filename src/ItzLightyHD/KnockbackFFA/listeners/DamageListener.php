<?php

namespace ItzLightyHD\KnockbackFFA\listeners;

use ItzLightyHD\KnockbackFFA\API;
use ItzLightyHD\KnockbackFFA\event\PlayerDeadEvent;
use ItzLightyHD\KnockbackFFA\event\PlayerKilledEvent;
use ItzLightyHD\KnockbackFFA\event\PlayerKillEvent;
use ItzLightyHD\KnockbackFFA\event\PlayerKillstreakEvent;
use ItzLightyHD\KnockbackFFA\Utils;
use ItzLightyHD\KnockbackFFA\utils\GameSettings;
use ItzLightyHD\KnockbackFFA\utils\KnockbackKit;
use ItzLightyHD\KnockbackFFA\utils\KnockbackPlayer;
use pocketmine\entity\projectile\Projectile;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\Listener;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems;
use pocketmine\player\Player;
use pocketmine\Server;

class DamageListener implements Listener
{
    /** @var self $instance */
    protected static DamageListener $instance;

    public function __construct()
    {
        self::$instance = $this;
    }

    /**
     * @return self
     */
    public static function getInstance(): self
    {
        return self::$instance;
    }

    /**
     * Handles void and fall damage events
     */
    public function onEntityDamage(EntityDamageEvent $event): void
    {
        $player = $event->getEntity();
        $gameWorld = GameSettings::getInstance()->world;
        if ($player instanceof Player && $player->getWorld()->getFolderName() === $gameWorld) {
            if ($event->getCause() === EntityDamageEvent::CAUSE_VOID) {
                $event->cancel(); /* Prevent void damage */
                $player->teleport($player->getWorld()->getSpawnLocation()); /* Bring player back to spawn */

                $lastDamager = KnockbackPlayer::getInstance()->lastDmg[strtolower($player->getName())] ?? "none";
                if ($lastDamager === "none") {
                    /* Player fell into void without a killer */
                    $deadEvent = new PlayerDeadEvent($player);
                    $deadEvent->call();
                } else {
                    /* Player was knocked into void by another player */
                    $killer = Server::getInstance()->getPlayerExact($lastDamager);
                    if ($killer instanceof Player) {
                        $killEvent = new PlayerKillEvent($killer, $player);
                        $killEvent->call();
                        $killedEvent = new PlayerKilledEvent($player, $killer);
                        $killedEvent->call();
                    }
                    KnockbackPlayer::getInstance()->lastDmg[strtolower($player->getName())] = "none"; /* Reset damager tracking */
                }
            } elseif ($event->getCause() === EntityDamageEvent::CAUSE_FALL) {
                $event->cancel(); /* No fall damage in game */
            }
        }
    }

    /**
     * Handles direct and projectile attacks between players
     */
    public function onEntityDamageByEntity(EntityDamageByEntityEvent $event): void
    {
        $player = $event->getEntity();
        $damager = $event->getDamager();
        if ($player instanceof Player && $player->getWorld()->getFolderName() === GameSettings::getInstance()->world) {
            $attacker = null;
            if ($damager instanceof Player) {
                $attacker = $damager; /* Direct hit by a player */
            } elseif ($damager instanceof Projectile) {
                $shooter = $damager->getOwningEntity();
                if ($shooter instanceof Player) {
                    $attacker = $shooter; /* Projectile hit, shooter is the attacker */
                }
            }
            if ($attacker !== null) {
                if ($attacker->getName() === $player->getName()) {
                    $event->cancel();
                    $attacker->sendMessage(GameSettings::getInstance()->getConfig()->get("prefix") . "§r§cYou can't hit yourself.");
                    return;
                }
                if ($damager instanceof Projectile) {
                    $event->setBaseDamage(0); /* Projectiles don't deal health damage */
                }
                $player->setHealth(20); /* Keep health full */
                $player->getHungerManager()->setSaturation(20); /* Keep saturation full */
                if (!Utils::canTakeDamage($player)) {
                    $event->cancel();
                    $attacker->sendMessage(GameSettings::getInstance()->getConfig()->get("prefix") . "§r§cYou can't hit the players here!");
                    return;
                }
                KnockbackPlayer::getInstance()->lastDmg[strtolower($player->getName())] = strtolower($attacker->getName()); /* Track who last hit the player */
                if ($damager instanceof Player && GameSettings::getInstance()->massive_knockback && $damager->getInventory()->getItemInHand()->getTypeId() === ItemTypeIds::STICK) {
                    $x = $damager->getDirectionVector()->x;
                    $z = $damager->getDirectionVector()->z;
                    $player->knockBack($x, $z, 0.6); /* Apply massive knockback with stick */
                }
                if ($damager instanceof Projectile) {
                    Utils::playSound("random.orb", $attacker); /* Sound for projectile hit */
                }
            }
        }
    }

    /**
     * Handles player death without a killer (e.g., walking into void)
     */
    public function onPlayerDead(PlayerDeadEvent $event): void
    {
        $player = $event->getPlayer();
        KnockbackPlayer::getInstance()->killstreak[strtolower($player->getName())] = 0; /* Reset killstreak */
        new KnockbackKit($player); /* Give fresh kit */
        Utils::playSound("random.glass", $player); /* Death sound */
        if (GameSettings::getInstance()->scoretag) {
            $player->setScoreTag(str_replace(["{kills}"], [0], GameSettings::getInstance()->getConfig()->get("scoretag-format"))); /* Update scoretag */
        }
        EssentialsListener::$cooldown[$player->getName()] = 0; /* Reset cooldown */
        $player->sendPopup(GameSettings::getInstance()->getConfig()->get("prefix") . "§r§cYou died"); /* Notify player */
    }

    /**
     * Handles player being killed by another player
     */
    public function onPlayerKilled(PlayerKilledEvent $event): void
    {
        $player = $event->getPlayer();
        $killer = $event->getKiller();
        KnockbackPlayer::getInstance()->killstreak[strtolower($player->getName())] = 0; /* Reset killstreak */
        new KnockbackKit($player); /* Give fresh kit */
        Utils::playSound("random.glass", $player); /* Death sound */
        if (GameSettings::getInstance()->scoretag) {
            $player->setScoreTag(str_replace(["{kills}"], [0], GameSettings::getInstance()->getConfig()->get("scoretag-format"))); /* Update scoretag */
        }
        EssentialsListener::$cooldown[$player->getName()] = 0; /* Reset cooldown */
        $player->sendPopup(GameSettings::getInstance()->getConfig()->get("prefix") . "§r§cYou were killed by §f" . $killer->getDisplayName()); /* Notify player */
    }

    /**
     * Handles player killing another player, including killstreak logic
     */
    public function onPlayerKill(PlayerKillEvent $event): void
    {
        $killer = $event->getPlayer();
        $killed = $event->getTarget();
        if (API::isSnowballsEnabled()) {
            $snowballs = VanillaItems::SNOWBALL();
            $killer->getInventory()->addItem($snowballs); /* Reward killer with snowball */
        }
        $killer->sendPopup(GameSettings::getInstance()->getConfig()->get("prefix") . "§r§aYou killed §f" . $killed->getDisplayName()); /* Notify killer */
        $killstreak = ++KnockbackPlayer::getInstance()->killstreak[strtolower($killer->getName())]; /* Increment killstreak */
        if (GameSettings::getInstance()->scoretag) {
            $killer->setScoreTag(str_replace(["{kills}"], [$killstreak], GameSettings::getInstance()->getConfig()->get("scoretag-format"))); /* Update killer's scoretag */
        }
        if ($killstreak % 5 === 0) {
            $killstreakEvent = new PlayerKillstreakEvent($killer);
            $killstreakEvent->call(); /* Trigger milestone event */
        }
        Utils::playSound("note.pling", $killer); /* Kill sound */
    }

    /**
     * Handles killstreak milestones (every 5 kills)
     */
    public function onPlayerKillstreak(PlayerKillstreakEvent $event): void
    {
        $player = $event->getPlayer();
        $killstreak = KnockbackPlayer::getInstance()->killstreak[strtolower($player->getName())];
        foreach ($player->getWorld()->getPlayers() as $p) {
            Utils::playSound("random.levelup", $p); /* Celebrate milestone */
            $p->sendPopup(GameSettings::getInstance()->getConfig()->get("prefix") . "§r§f" . $player->getDisplayName() . "§r§6 is at §e" . $killstreak . "§6 kills"); /* Announce to all */
        }
    }
}