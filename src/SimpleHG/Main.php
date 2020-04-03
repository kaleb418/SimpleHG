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

namespace SimpleHG;

use pocketmine\plugin\PluginBase;

use pocketmine\event\Listener;
use pocketmine\event\block\SignChangeEvent;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;

use pocketmine\item\Item;

use pocketmine\entity\Item as ItemEntity;

use pocketmine\tile\Chest;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;

use pocketmine\Player;

use pocketmine\level\Position;

use pocketmine\math\Vector3;

use SimpleHG\Tasks\GameTimer;

// TODO: Allow commands to configure

class Main extends PluginBase implements Listener{
    public function onLoad(){
        if(!is_dir("./plugins/SimpleHG")){
            mkdir("./plugins/SimpleHG");
        }
        
        if(!file_exists("./plugins/SimpleHG/signs.json")){
            touch("./plugins/SimpleHG/signs.json");
            $JSONFile = fopen("./plugins/SimpleHG/signs.json", "w");
            fwrite($JSONFile, "{\"signs\":[]}");
            fclose($JSONFile);
        }
        
        $this->getLogger()->info("§eLoaded SimpleHG v{$this->getDescription()->getVersion()}§r");
    }
    
    public function onEnable(){
        
        $this->cfg = $this->getConfig();
        if($this->cfg->get("enabled") == false){
            $this->setEnabled(false);
            return;
        }
        
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->getLogger()->info("§aEnabled SimpleHG v{$this->getDescription()->getVersion()}§r");
        
        $this->gameRunning = false;
        $this->playersInGame = [];
        $this->filledSpawns = [];
        $this->gameTask = null;
        
        $fileContents = file_get_contents("./plugins/SimpleHG/signs.json");
        $arrayData = json_decode($fileContents, true);
        $this->data = $arrayData;
        $this->signs = $arrayData["signs"];
        
        $signBlockList = [];
        foreach($this->signs as $s){
            $level = $this->getServer()->getLevelByName($s[3]);
            array_push($signBlockList, $level->getTile(new Vector3($s[0], $s[1], $s[2])));
        }
        
        foreach($signBlockList as $b){
            $lines = $b->getText();
            $b->setText($lines[0], $lines[1], "§a0/{$this->cfg->get("max-players")}§r", $lines[3]);
        }
    }
    
    public function onDisable(){
        $this->getLogger()->info("§cDisabled SimpleHG v{$this->getDescription()->getVersion()}§r");
        
        $this->data["signs"] = $this->signs;
        $codedJSON = json_encode($this->data);
        file_put_contents("./plugins/SimpleHG/signs.json", $codedJSON);
        
        if($this->gameRunning){
            $this->stopGame();
        }
    }
    
    public function onJoin(PlayerJoinEvent $event){
        $event->getPlayer()->isPlayingSimpleHG = false;
        $event->getPlayer()->currentSpawnPosition = null;
    }
    
    public function startGame(){
        $this->gameTask = new GameTimer(
            $this,
            $this->cfg->get("starting-int"),
            $this->cfg->get("alert-players"),
            $this->cfg->get("alert-intervals"),
            $this->cfg->get("deathmatch-countdown"),
            $this->cfg->get("force-end")
        );
        $h = $this->getServer()->getScheduler()->scheduleRepeatingTask($this->gameTask, 20);
        $this->gameTask->setHandler($h);
        $this->gameRunning = true;
        
        $entityList = $this->getServer()->getLevelByName($this->cfg->get("world"));
        foreach($entityList as $ent){
            if($ent instanceof ItemEntity){
                $ent->close();
            }
        }
        
        $chestCoordList = $this->cfg->get("chest-coords");
        foreach($chestCoordList as $coords){
            $block = ($this->getServer()->getLevelByName($this->cfg->get("world")))->getTile(new Vector3($coords[0], $coords[1], $coords[2]));
            if($block instanceof Chest){
                $block->getInventory()->clearAll();
            }else{
                $this->getServer()->getLogger()->warning("§cSimpleHG detected an incorrect game chest location.§r");
                $this->setEnabled(false);
                return;
            }
        }
            
        $signBlockList = [];
        foreach($this->signs as $s){
            $level = $this->getServer()->getLevelByName($s[3]);
            array_push($signBlockList, $level->getTile(new Vector3($s[0], $s[1], $s[2])));
        }
        
        foreach($signBlockList as $b){
            $lines = $b->getText();
            $b->setText($lines[0], "§dTap to Join§r", "§a0/{$this->cfg->get("max-players")}§r", $lines[3]);
        }
    }
    
    public function stopGame(){
        foreach($this->playersInGame as $k => $playerToLeave){
            $playerToLeave->teleport($playerToLeave->getSpawn());
            $playerToLeave->isPlayingSimpleHG = false;
            $playerToLeave->currentSpawnPosition = null;
            $playerToLeave->sendMessage("§cGame has stopped.§r");
            $playerToLeave->setHealth(20);
        }
        $this->playersInGame = [];
        $this->filledSpawns = [];
        $this->getServer()->getScheduler()->cancelTask($this->gameTask->getTaskId());
        $this->gameTask = null;
        $this->gameRunning = false;
        
        $signBlockList = [];
        foreach($this->signs as $s){
            $level = $this->getServer()->getLevelByName($s[3]);
            array_push($signBlockList, $level->getTile(new Vector3($s[0], $s[1], $s[2])));
        }
        
        foreach($signBlockList as $b){
            $lines = $b->getText();
            $b->setText($lines[0], "§cNo Game Running§r", "§a0/{$this->cfg->get("max-players")}§r", $lines[3]);
        }
    }
    
    public function gameOver(){
        $winnerMaybe = array_shift($this->playersInGame);
        $this->playersInGame[$winnerMaybe->getName()] = $winnerMaybe;
        
        if(count($this->playersInGame) === 1){
            $winMsg = $this->cfg->get("winning-message");
            $winMsg = str_replace('{winner}', $winnerMaybe->getName(), $winMsg);
            $this->getServer()->broadcastMessage("{$winMsg}");
        }
        foreach($this->playersInGame as $k => $playerToLeave){
            $playerToLeave->teleport($playerToLeave->getSpawn());
            $playerToLeave->isPlayingSimpleHG = false;
            $playerToLeave->currentSpawnPosition = null;
            $playerToLeave->sendMessage("{$this->cfg->get("force-end-message")}");
            $playerToLeave->setHealth(20);
            $playerToLeave->getInventory()->clearAll();
        }
        
        $this->playersInGame = [];
        $this->filledSpawns = [];
        $this->getServer()->getScheduler()->cancelTask($this->gameTask->getTaskId());
        $this->gameTask = null;
        $this->gameRunning = false;
        
        $signBlockList = [];
        foreach($this->signs as $s){
            $level = $this->getServer()->getLevelByName($s[3]);
            array_push($signBlockList, $level->getTile(new Vector3($s[0], $s[1], $s[2])));
        }
        
        foreach($signBlockList as $b){
            $lines = $b->getText();
            $b->setText($lines[0], "§cNo Game Running§r", "§a0/{$this->cfg->get("max-players")}§r", $lines[3]);
        }
    }
    
    public function addPlayer(Player $player){
        
        if($this->gameRunning){
            if(!$this->gameTask->hasStarted){
                if(!$player->isPlayingSimpleHG){
                    $spawnList = $this->cfg->get("spawn-coords");
                    $spotToFill = null;
                    foreach($spawnList as $sp){
                        if(!(in_array($sp, $this->filledSpawns))){
                            // Spawn available, adding player...
                            array_push($this->filledSpawns, $sp);
                            $spotToFill = $sp;
                            break;
                        }
                    }
                    
                    if($spotToFill !== null){
                        $player->currentSpawnPosition = $spotToFill;
                        
                        $this->playersInGame[$player->getName()] = $player;
                        
                        $joinMsg = $this->cfg->get("player-join-message");
                        $joinMsg = str_replace('{player}', $player->getName(), $joinMsg);
                        foreach($this->playersInGame as $k => $p){
                            $p->sendMessage("$joinMsg");
                        }
                        
                        if($this->cfg->get("force-adventure")){
                            $player->setGamemode(2);
                        }
                        else if($this->cfg->get("force-survival")){
                            $player->setGamemode(0);
                        }
                        
                        $player->isPlayingSimpleHG = true;
                        $player->getInventory()->clearAll();
                        $player->teleport(new Position($spotToFill[0], $spotToFill[1], $spotToFill[2], $this->getServer()->getLevelByName($this->cfg->get("world"))));
                        
                        $signBlockList = [];
                        foreach($this->signs as $s){
                            $level = $this->getServer()->getLevelByName($s[3]);
                            array_push($signBlockList, $level->getTile(new Vector3($s[0], $s[1], $s[2])));
                        }
                        
                        foreach($signBlockList as $b){
                            $lines = $b->getText();
                            $playersInGameCount = count($this->playersInGame);
                            $b->setText($lines[0], $lines[1], "§a{$playersInGameCount}/{$this->cfg->get("max-players")}§r", $lines[3]);
                        }
                    }else{
                        $player->sendMessage($this->cfg->get("max-players-error-message")); // No empty spots
                    }
                }else{
                    $player->sendMessage("§cYou're already in a game.§r"); // Player Already In Game
                }
            }else{
                $player->sendMessage($this->cfg->get("game-started-error-message")); // Game already started
            }
        }else{
            $player->sendMessage("§cNo game is currently running.§r"); // No Game Running
        }
    }
    
    // Function to remove player during countdown. For removing player during countdown, use $this->killAndRemovePlayer() method
    public function removePlayer(Player $player){
        if($this->gameRunning){
            
            $keyToUnset = null;
            $playerName = $player->getName();
            foreach($this->playersInGame as $k => $inGamePlayer){
                if($playerName === $inGamePlayer->getName()){
                    $keyToUnset = $k;
                    break;
                }
            }
            
            if($player->isPlayingSimpleHG){
                
                unset($this->playersInGame[$keyToUnset]);
                
                $key = array_search($player->currentSpawnPosition, $this->filledSpawns);
                unset($this->filledSpawns[$key]);
                $player->currentSpawnPosition = null;
                
                $leaveMsg = $this->cfg->get("player-leave-message");
                $leaveMsg = str_replace('{player}', $playerName, $leaveMsg);
                
                foreach($this->playersInGame as $playerToSendMsg){
                    $playerToSendMsg->sendMessage("$leaveMsg");
                }
                
                $player->sendMessage("§cYou've left the game.§r");
                $player->isPlayingSimpleHG = false;
                $player->teleport($player->getSpawn());
                
                $signBlockList = [];
                foreach($this->signs as $s){
                    $level = $this->getServer()->getLevelByName($s[3]);
                    array_push($signBlockList, $level->getTile(new Vector3($s[0], $s[1], $s[2])));
                }
                
                foreach($signBlockList as $b){
                    $lines = $b->getText();
                    $playersInGameCount = count($this->playersInGame);
                    $b->setText($lines[0], $lines[1], "§a{$playersInGameCount}/{$this->cfg->get("max-players")}§r", $lines[3]);
                }
            }else{
                $player->sendMessage("§cYou're not in a game currently.§r"); // Player Not In Game
            }
        }else{
            $player->sendMessage("§cNo game is currently running.§r"); // No Game Running
        }
    }
    
    // Use to remove a player from a game that has already started
    public function killAndRemovePlayer(Player $player){
        if($this->gameRunning){
            
            $keyToUnset = null;
            $playerName = $player->getName();
            foreach($this->playersInGame as $k => $inGamePlayer){
                if($playerName === $inGamePlayer->getName()){
                    $keyToUnset = $k;
                    break;
                }
            }
            
            if($player->isPlayingSimpleHG){
                
                unset($this->playersInGame[$keyToUnset]);
                
                $key = array_search($player->currentSpawnPosition, $this->filledSpawns);
                unset($this->filledSpawns[$key]);
                $player->currentSpawnPosition = null;
                
                $leaveMsg = $this->cfg->get("player-leave-message");
                $leaveMsg = str_replace('{player}', $playerName, $leaveMsg);
                
                foreach($this->playersInGame as $playerToSendMsg){
                    $playerToSendMsg->sendMessage("$leaveMsg");
                }
                
                $player->sendMessage("§cYou've left the game.§r");
                $player->isPlayingSimpleHG = false;
                $player->kill();
                
                $signBlockList = [];
                foreach($this->signs as $s){
                    $level = $this->getServer()->getLevelByName($s[3]);
                    array_push($signBlockList, $level->getTile(new Vector3($s[0], $s[1], $s[2])));
                }
                
                foreach($signBlockList as $b){
                    $lines = $b->getText();
                    $playersInGameCount = count($this->playersInGame);
                    $b->setText($lines[0], $lines[1], "§a{$playersInGameCount}/{$this->cfg->get("max-players")}§r", $lines[3]);
                }
            }else{
                $player->sendMessage("§cYou're not in a game currently.§r"); // Player Not In Game
            }
        }else{
            $player->sendMessage("§cNo game is currently running.§r"); // No Game Running
        }
    }
    
    public function fillChests(){  
        $chestsEnabled = $this->cfg->get("fill-chests");
        if($chestsEnabled){
            $chestCoords = $this->cfg->get("chest-coords");
            $chestItems = $this->cfg->get("chest-contents");
            $minChestNum = $this->cfg->get("min-chest-contents");
            $maxChestNum = $this->cfg->get("max-chest-contents");
            $alreadyUsedItemIDs = [];
            
            foreach($chestCoords as $chestLocation){
                $block = $this->getServer()->getLevelByName("{$this->cfg->get("world")}")->getTile(new Vector3($chestLocation[0], $chestLocation[1], $chestLocation[2]));
                if($block instanceof Chest){
                    $chestInv = $this->getServer()->getLevelByName("{$this->cfg->get("world")}")->getTile(new Vector3($chestLocation[0], $chestLocation[1], $chestLocation[2]))->getInventory();
                    $chestInv->clearAll();
                    $chestTimesItemAdded = 0;
                    
                    $chestSize = $chestInv->getSize();
                    $timesToAddItems = rand($minChestNum, $maxChestNum);
                    $chestAddedTimes = 0;
                    while($chestAddedTimes !== $timesToAddItems){
                        $randomIndex = rand(0, $chestSize - 1);
                        if($chestInv->getItem($randomIndex)->getName() === "Air" && $chestInv->getItem($randomIndex)->getId() === 0){
                            $randomChestContent = $chestItems[(rand(0, count($chestItems) - 1))];
                            $randomItemID = $randomChestContent[0];
                            $maxNumOfItem = $randomChestContent[1];
                            $numOfItem = rand(1, $maxNumOfItem);
                            
                            $stringArray = explode(':', $randomItemID);
                            $randomItemID = $stringArray[0];
                            $meta = 0;
                            if(isset($stringArray[1])){
                                $meta = $stringArray[1];
                            }
                            
                            $chestInv->setItem($randomIndex, new Item($randomItemID, $meta, $numOfItem));
                            
                            $chestAddedTimes++;
                        }
                    }
                }else{
                    $this->getServer()->getLogger()->warning("§cSimpleHG detected an incorrect game chest location.§r");
                    $this->setEnabled(false);
                    return;
                }
            }
        }
    }
    
    public function onPlayerDeath(PlayerDeathEvent $event){
        $cause = $event->getEntity()->getLastDamageCause();
        if($cause instanceof EntityDamageByEntityEvent){
            $killer = $cause->getDamager();
            if($killer instanceof Player){
                $killerName = $killer->getName();
                $player = $event->getPlayer();
                
                $deathMsg = $this->cfg->get("death-message");
                $deathMsg = str_replace('{victim}', $player->getname(), $deathMsg);
                $deathMsg = str_replace('{killer}', $killerName, $deathMsg);
                
                foreach($this->playersInGame as $playerToSendMsg){
                    $playerToSendMsg->sendMessage("$deathMsg");
                }
                
                $playerName = $player->getName();
                
                unset($this->playersInGame[$playerName]);
                
                $key = array_search($player->currentSpawnPosition, $this->filledSpawns);
                unset($this->filledSpawns[$key]);
                $player->currentSpawnPosition = null;
                
                $player->isPlayingSimpleHG = false;
                
                $signBlockList = [];
                foreach($this->signs as $s){
                    $level = $this->getServer()->getLevelByName($s[3]);
                    array_push($signBlockList, $level->getTile(new Vector3($s[0], $s[1], $s[2])));
                }
                
                foreach($signBlockList as $b){
                    $lines = $b->getText();
                    $playersInGameCount = count($this->playersInGame);
                    $b->setText($lines[0], $lines[1], "§a{$playersInGameCount}/{$this->cfg->get("max-players")}§r", $lines[3]);
                }
            }
        }
        else if($event->getPlayer()->isPlayingSimpleHG){
            $player = $event->getPlayer();
            
            $deathMsg = "§e{$player->getName()} died.";
            
            foreach($this->playersInGame as $playerToSendMsg){
                $playerToSendMsg->sendMessage("$deathMsg");
            }
            
            $playerName = $player->getName();
            
            unset($this->playersInGame[$playerName]);
            
            $key = array_search($player->currentSpawnPosition, $this->filledSpawns);
            unset($this->filledSpawns[$key]);
            $player->currentSpawnPosition = null;
            
            $player->isPlayingSimpleHG = false;
            
            $signBlockList = [];
            foreach($this->signs as $s){
                $level = $this->getServer()->getLevelByName($s[3]);
                array_push($signBlockList, $level->getTile(new Vector3($s[0], $s[1], $s[2])));
            }
            
            foreach($signBlockList as $b){
                $lines = $b->getText();
                $playersInGameCount = count($this->playersInGame);
                $b->setText($lines[0], $lines[1], "§a{$playersInGameCount}/{$this->cfg->get("max-players")}§r", $lines[3]);
            }
        }
    }
    
    public function onPlayerQuit(PlayerQuitEvent $event){
        if($event->getPlayer()->isPlayingSimpleHG){
            if($this->gameTask->hasStarted){
                killAndRemovePlayer($event->getPlayer());
            }else{
                removePlayer($event->getPlayer());
            }
        }
    }
    
    public function onPvP(EntityDamageEvent $event){
        if($event instanceof EntityDamageByEntityEvent){
            $target = $event->getEntity();
            $attacker = $event->getDamager();
            if($target instanceof Player && $attacker instanceof Player){
                if($target->isPlayingSimpleHG && $attacker->isPlayingSimpleHG){
                    if($this->gameTask->playersInvincible && $this->gameTask->hasStarted){
                        $event->setCancelled();
                        $t = $this->cfg->get("invincibility") + $this->gameTask->countdownLength;
                        $tr = $t - $this->gameTask->seconds;
                        $attacker->sendMessage("§cYou cannot attack for §f{$tr} §cseconds!§r");
                    }
                }
            }
        }
    }
    
    public function onPlayerChat(PlayerChatEvent $event){
        if($event->getPlayer()->isPlayingSimpleHG && !$this->cfg->get("player-chat")){
            $recipients = [];
            foreach($this->playersInGame as $k => $inGamePlayer){
                array_push($recipients, $inGamePlayer);
            }
            $event->setRecipients($recipients);
        }
    }
    
    public function onBlockBreak(BlockBreakEvent $event){
        $player = $event->getPlayer();
        if($player->isPlayingSimpleHG && !$this->cfg->get("player-break-block")){
            $event->setCancelled();
        }
    }
    
    public function onBlockPlace(BlockPlaceEvent $event){
        $player = $event->getPlayer();
        if($player->isPlayingSimpleHG && !$this->cfg->get("player-place-block")){
            $event->setCancelled();
        }
    }
    
    public function onSignEdit(SignChangeEvent $event){
        $player = $event->getPlayer();
        
        if($event->getPlayer()->hasPermission("simplehg.createsign")){
            $lines = $event->getLines();
            $line1 = $lines[0];
            if($line1 === '\SimpleHG/'){
                $event->setLine(0, "§eHunger Games§r");
                $event->setLine(1, ($this->gameRunning ? "§dTap to Join§r" : "§cNo Game Running§r"));
                $event->setLine(2, "§a0/{$this->cfg->get("max-players")}§r");
                $event->setLine(3, "§b----------§r");
                
                array_push($this->signs, [$event->getBlock()->getX(), $event->getBlock()->getY(), $event->getBlock()->getZ(), $event->getBlock()->getLevel()->getName()]);
                $event->getPlayer()->sendMessage("§aCreated a SimpleHG portal.§r");
            }
        }
    }
    
    public function onSignBreak(BlockBreakEvent $event){
        
        if($event->getBlock()->getId() === 63 || $event->getBlock()->getId() === 68){
            if(in_array([$event->getBlock()->getX(), $event->getBlock()->getY(), $event->getBlock()->getZ(), $event->getBlock()->getLevel()->getName()], $this->signs)){
                // Block is a confirmed HG sign
                if(!$event->getPlayer()->hasPermission("simplehg.deletesign")){
                    $event->getPlayer()->sendMessage("§cYou cannot break this block.§r");
                    $event->setCancelled();
                    return;
                }
                $index = null;
                $timesIndexed = 0;
                foreach($this->signs as $key => $value){
                    if($value === [$event->getBlock()->getX(), $event->getBlock()->getY(), $event->getBlock()->getZ(), $event->getBlock()->getLevel()->getName()]){
                        $index = $key;
                        $timesIndexed++;
                    }
                }
                if($timesIndexed === 1){
                    unset($this->signs[$index]);
                    $event->getPlayer()->sendMessage("§cDeleted the SimpleHG portal.§r");
                }else{
                    $event->getPlayer()->sendMessage("§cUh oh, a fatal error has occurred. Please check the server logs.§r");
                    $this->getServer()->getLogger()->warning("§cWarning: JSON data detects an incorrect number of duplicate sign coordinates where only one should be. This could have been caused by a level name change, or a few other occurences. Please stop your server and delete your plugins/SimpleHG/signs.json data file.§r");
                    $this->setEnabled(false);
                }
            }
        }
    }
    
    public function onSignTouch(PlayerInteractEvent $event){
        if($event->getBlock()->getId() === 63 || $event->getBlock()->getId() === 68){
            if(in_array([$event->getBlock()->getX(), $event->getBlock()->getY(), $event->getBlock()->getZ(), $event->getBlock()->getLevel()->getName()], $this->signs)){
                // Block is a confirmed HG sign
                $this->addPlayer($event->getPlayer());
            }
        }
    }
    
    public function onPlayerMove(PlayerMoveEvent $event){
        if($this->cfg->get("world") === $event->getPlayer()->getLevel()->getName()){
            if($event->getPlayer()->isPlayingSimpleHG){
                if($this->gameTask !== null){
                    if(!$this->gameTask->hasStarted){
                        if(!$this->cfg->get("allow-movement")){
                            if(!(($event->getTo()->getX() === $event->getFrom()->getX()) && ($event->getTo()->getY() === $event->getFrom()->getY()) && ($event->getTo()->getZ() === $event->getFrom()->getZ()))){
                                // Player actually moved, not just changed camera view
                                $event->getPlayer()->teleport(new Vector3($event->getFrom()->getX(), $event->getFrom()->getY(), $event->getFrom()->getZ()));
                            }
                        }
                    }
                }
            }
        }
    }
    
    public function onCommand(CommandSender $sender, Command $command, $label, array $args){
        $commandName = $command->getName();
        if($commandName === 'starthg'){
            if(count($args) !== 0){
                return false;
            }
            
            if(!$this->gameRunning){
                $this->startGame();
                $sender->sendMessage("§aStarted a SimpleHG game.§r");
                return true;
            }else{
                $sender->sendMessage("§cThere's already a game running. Use §f/stophg §cto stop the current game.§r");
                
                return true;
            }
        }
        else if($commandName === 'stophg'){
            if(count($args) !== 0){
                return false;
            }
            
            if($this->gameRunning){
                $this->stopGame();
                $sender->sendMessage("§cStopped the SimpleHG game.§r");
                return true;
            }else{
                $sender->sendMessage("§cNo game is running. Use §f/starthg §cto start a game.§r");
                
                return true;
            }
        }
        else if($commandName === 'gettime'){
            if(count($args) !== 0){
                return false;
            }
            
            if($this->gameRunning){
                if(!$this->gameTask->hasStarted){
                    $timeRemaining = $this->gameTask->countdownLength - $this->gameTask->seconds;
                    $sender->sendMessage("§9The game will start in §f{$timeRemaining} §9seconds.§r");
                    
                    return true;
                }else{
                    $sender->sendMessage("§cThe game has already started.§r");
                    
                    return true;
                }
            }
        }
        else if($commandName === 'joinhg'){
            if(count($args) !== 0){
                return false;
            }
            if($sender instanceof Player){
                $this->addPlayer($sender);
                
                return true;
            }else{
                $sender->sendMessage("§cCommand only for players.§r");
                
                return true;
            }
        }
        else if($commandName === 'leavehg'){
            if(count($args) !== 0){
                return false;
            }
            if($sender instanceof Player){
                if($this->gameRunning){
                    if(!$this->gameTask->hasStarted){
                        $this->removePlayer($sender);
                    }else{
                        $this->killAndRemovePlayer($sender);
                    }
                }else{
                    $sender->sendMessage("§cNo game is currently running.§r");
                }
                
                return true;
            }else{
                $sender->sendMessage("§cCommand only for players.§r");
                
                return true;
            }
        }
    }
}