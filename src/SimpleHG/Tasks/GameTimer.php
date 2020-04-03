<?php

/*
    COPYRIGHT 2017, KALEB WASMUTH.
    
    This file is part of SimpleHG.
    
    SimpleHG is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.
    
    SimpleHG is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.
    
    You should have received a copy of the GNU General Public License
    along with this program.  If not, see http://www.gnu.org/licenses/.
*/

namespace SimpleHG\Tasks;

use pocketmine\scheduler\PluginTask;

use pocketmine\math\Vector3;

use pocketmine\level\Position;
use pocketmine\level\sound\ClickSound;
use pocketmine\level\sound\AnvilFallSound;
use pocketmine\level\sound\BlazeShootSound;

use SimpleHG\Main;

// Task that counts up for as long as a game is run
class GameTimer extends PluginTask{
    public function __construct(Main $plugin, int $countdownLength, bool $doAlerts, array $countdownAlertIntervals, int $deathmatchStart, int $forceEndNum){
        parent::__construct($plugin);
        $this->plugin = $plugin;
        $this->seconds = 0;
        
        $this->countdownLength = $countdownLength;
        $this->doCountdownAlerts = $doAlerts;
        $this->countdownAlertIntervals = $countdownAlertIntervals;
        $this->hasStarted = false;
        $this->deathMStartNum = $deathmatchStart;
        $this->forceEndNum = $forceEndNum;
        
        $this->hasStarted = false;
        $this->playersInvincible = true;
        $this->dmStarted = false;
        $this->dmSeconds = 0;
        $this->playerNamesInDm = [];
    }
    
    // Runs every 20 ticks (every second)
    public function onRun($tick){
        
        $alertExists = false;
        foreach($this->countdownAlertIntervals as $interval){
            if($interval === ($this->countdownLength - $this->seconds)){
                $alertExists = true;
                break;
            }
        }
        
        if($this->doCountdownAlerts && $alertExists){
            $msg = $this->plugin->cfg->get("alert-message");
            $msg = str_replace('{time}', ($this->countdownLength - $this->seconds), $msg);
            foreach($this->plugin->playersInGame as $k => $p){
                $p->sendMessage("{$msg}");
                $vector = new Vector3($p->getX(), $p->getY(), $p->getZ());
                $p->getLevel()->addSound(new ClickSound($vector), [$p]);
            }
        }
        
        if($this->countdownLength === $this->seconds){
            if($this->plugin->cfg->get("force-start") || (count($this->plugin->playersInGame) >= $this->plugin->cfg->get("min-players"))){
                foreach($this->plugin->playersInGame as $k => $playerInGame){
                    $playerInGame->sendMessage("{$this->plugin->cfg->get("game-start-message")}");
                    $playerInGame->teleport(new Position($playerInGame->currentSpawnPosition[0], $playerInGame->currentSpawnPosition[1], $playerInGame->currentSpawnPosition[2], $this->plugin->getServer()->getLevelByName($this->plugin->cfg->get("world"))));
                    $vector = new Vector3($playerInGame->getX(), $playerInGame->getY(), $playerInGame->getZ());
                    $playerInGame->getLevel()->addSound(new AnvilFallSound($vector), [$playerInGame]);
                }
                
                $this->plugin->fillChests();
                
                $signBlockList = [];
                foreach($this->plugin->signs as $s){
                    $level = $this->plugin->getServer()->getLevelByName($s[3]);
                    array_push($signBlockList, $level->getTile(new Vector3($s[0], $s[1], $s[2])));
                }
                
                foreach($signBlockList as $b){
                    $lines = $b->getText();
                    $b->setText($lines[0], "§9Game Started§r", $lines[2], $lines[3]);
                }
                
                if(!($this->plugin->cfg->get("invincibility") > 0)){
                    $this->playersInvincible = false;
                }
                
                $this->hasStarted = true;
            }else{
                foreach($this->plugin->playersInGame as $k => $playerInGame){
                    $playerInGame->sendMessage("§cAt least §f{$this->plugin->cfg->get("min-players")} §cplayers must be in the game. Countdown timer restarted.§r");
                }
                $this->seconds = 0;
            }
        }
        
        if($this->seconds === $this->deathMStartNum && $this->plugin->cfg->get("deathmatch")){
            $this->dmSeconds = $this->seconds;
            $this->dmStarted = true;
        }
        
        // Every three seconds, a player is added to the DM
        if($this->dmStarted && $this->dmSeconds === $this->seconds){
            foreach($this->plugin->playersInGame as $k => $v){
                if(!in_array($k, $this->playerNamesInDm)){
                    foreach($this->plugin->playersInGame as $keyName => $objValue){
                        $objValue = $this->plugin->getServer()->getPlayer($keyName);
                        $vector = new Vector3($objValue->getX(), $objValue->getY(), $objValue->getZ());
                        $objValue->getLevel()->addSound(new BlazeShootSound($vector), [$objValue]);
                        $objValue->sendMessage("§f{$k} §ewent to the Deathmatch!§r");
                    }
                    
                    $dmCoords = $this->plugin->cfg->get("deathmatch-coords");
                    $v->teleport(new Position($dmCoords[0], $dmCoords[1], $dmCoords[2], $this->plugin->getServer()->getLevelByName($this->plugin->cfg->get("world"))));
                    $v->sendMessage("{$this->plugin->cfg->get("deathmatch-message")}");
                    
                    array_push($this->playerNamesInDm, $k);
                    break;
                }
            }
            $this->dmSeconds += 3;
        }
        
        if(count($this->plugin->playersInGame) <= 1 && $this->hasStarted){
            $this->plugin->gameOver();
        }
        
        if($this->plugin->cfg->get("invincibility") >= 0 && ($this->plugin->cfg->get("invincibility") + $this->countdownLength) === $this->seconds){
            foreach($this->plugin->playersInGame as $k => $playerInGame){
                $playerInGame->sendMessage("§eYou are no longer invincible. Scary!§r");
            }
            $this->playersInvincible = false;
        }
        
        if($this->plugin->cfg->get("invincibility") === -1){
            $this->playersInvincible = false;
        }
        
        if($this->seconds === $this->forceEndNum){
            $this->plugin->gameOver();
        }
        
        $this->seconds++;
    }
}