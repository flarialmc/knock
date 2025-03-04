<?php

namespace ItzLightyHD\KnockbackFFA\listeners;

use ItzLightyHD\KnockbackFFA\event\PlayerKillEvent;
use ItzLightyHD\KnockbackFFA\utils\KnockbackPlayer;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\player\Player;
use pocketmine\Server;

class PlayerMoveListener implements Listener
{
public function onMove(PlayerMoveEvent $event): void {

    $player = $event->getPlayer();
    if ($player->getPosition()->y < 52) {
        $killedBy = Server::getInstance()->getPlayerExact(KnockbackPlayer::getInstance()->lastDmg[strtolower($player->getName())]);
        if ($killedBy instanceof Player) {

            $event = new PlayerKillEvent($killedBy, $player);
            $event->call();

        }

    }
}
}