<?php

namespace dirouc\FormAuth;

use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\inventory\CraftItemEvent;
use pocketmine\event\player\PlayerAchievementAwardedEvent;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\player\PlayerCommandPreprocessEvent;
use pocketmine\event\player\PlayerDropItemEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerItemConsumeEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerLoginEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\player\PlayerPreLoginEvent;
use pocketmine\Player;
use pocketmine\OfflinePlayer;
use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\utils\Config;
use pocketmine\utils\{TextFormat, TextFormat as C};
use pocketmine\scheduler\Task;
use pocketmine\command\{Command, CommandSender};

class FormAuth extends PluginBase implements Listener {

    private $auth_players = [];

    private $auth_attempts = [];

    public function onEnable() : void {
        @mkdir($this->getDataFolder());
        @mkdir($this->getDataFolder() . "players/");
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->formAPI = $this->getServer()->getPluginManager()->getPlugin("FormAPI");
        if(!$this->formAPI){
            $this->getLogger()->info(TextFormat::YELLOW . "FormAPI plugin not found! Disabling the plugin...");
            $this->getLogger()->info(TextFormat::DARK_RED . "Plugin has been disabled.");
            $this->getServer()->getPluginManager()->disablePlugin($this);
        } elseif($this->formAPI) {
            $this->getLogger()->info(TextFormat::GREEN . "FormAPI plugin found! Enabling the plugin...");
            $this->getLogger()->info(TextFormat::DARK_GREEN . "Plugin enabled.");
        }
    }

    public function getPlayerData(string $player) {
        if($this->isPlayerRegistered($player)) {
            $cfg = new Config($this->getDataFolder() . "players/" . strtolower($player . ".json"), Config::JSON);
            return $cfg->getAll();
        } else {
            return $this->isPlayerRegistered($player);
        }
    }

    public function isPlayerRegistered(string $player) {
        $status = file_exists($this->getDataFolder() . "players/" . strtolower($player . ".json"));
        return $status;
    }

    public function isPlayerAuthenticated(Player $player) : bool {
        return isset($this->auth_players[strtolower($player->getName())]);
    }

    public function registerPlayer(Player $player, string $password) {
        if($this->isPlayerRegistered($player->getName())) {
                $player->sendMessage(C::DARK_BLUE . "> " . C::RED . "You are already registered.");
            return $this->createForm(0, $player);
        } else {
            if(mb_strlen($password) <= 5) {
                $player->sendMessage(C::DARK_BLUE . "> " . C::RED . "Your password is too short.");
                return $this->createForm(0, $player);
            } elseif(mb_strlen($password) >= 16) {
                $player->sendMessage(C::DARK_BLUE . "> " . C::RED . "Your password is too long.");
                return $this->createForm(0, $player);
            } else {
                $data = new Config($this->getDataFolder() . "players/" . strtolower($player->getName() . ".json"), Config::JSON);
                $data->set("password", password_hash($password, PASSWORD_DEFAULT));
                $data->save();
                $this->auth_players[strtolower($player->getName())] = "";
                $player->sendPopup(C::GREEN . "You are now authenticated in this server.");
                return $player->sendMessage(C::DARK_BLUE . "> " . C::GREEN . "You have been registered.");
            }
        }
    }

    public function authenticatePlayer(Player $player, string $password) {
        if($this->isPlayerRegistered($player->getName())) {
            if(!$this->isPlayerAuthenticated($player)) {
                $data = new Config($this->getDataFolder() . "players/" . strtolower($player->getName() . ".json"), Config::JSON);
                if(password_verify($password, $data->get("password"))) {
                    $data->save();
                    $this->auth_players[strtolower($player->getName())] = "";
                    $player->sendPopup(C::GREEN . "You have been logged in.");
                    return $player->sendMessage(C::DARK_BLUE . "> " . C::GREEN . "You are now logged in.");
                } else {
                    if(isset($this->auth_attempts[strtolower($player->getName())])) {
                        $this->auth_attempts[strtolower($player->getName())]++;
                    } else {
                        $this->auth_attempts[strtolower($player->getName())] = 1;
                    }
                    if($this->auth_attempts[strtolower($player->getName())] >= 5) {
                        $player->close("", C::RED . "Too many password attempts.");
                        unset($this->auth_attempts[strtolower($player->getName())]);
                    }
                    $player->sendMessage(C::DARK_BLUE . "> " . C::RED . "Your password is wrong, try again.");
                    return $this->createForm(1, $player);
                }
            } else {
                $player->sendMessage(C::DARK_BLUE . "> " . C::RED . "You are already logged in.");
            }
        } else {
            return $this->isPlayerRegistered($player->getName());
        }
    }

    public function changePlayerPassword($player, string $new_password) {
        if($player instanceof Player || $player instanceof OfflinePlayer) {
            if($this->isPlayerRegistered($player->getName())) {
                if(mb_strlen($new_password) <= 5) {
                    $player->sendMessage(C::DARK_BLUE . "> " . C::RED . "Your password is too short.");
                } elseif(mb_strlen($new_password) >= 16) {
                    $player->sendMessage(C::DARK_BLUE . "> " . C::RED . "Your password is too long.");
                } else {
                    $data = new Config($this->getDataFolder() . "players/" . strtolower($player->getName() . ".json"), Config::JSON);
                    $data->set("password", password_hash($new_password, PASSWORD_DEFAULT));
                    $data->save();
                    return $player->sendMessage(C::DARK_BLUE . "> " . C::GREEN . "You have successfuly changed your password.");
                }
            }else{
                return $this->isPlayerRegistered($player->getName());
            }
        }else{
            return $player->sendMessage(C::DARK_BLUE . "> " . C::RED . "You are not registered.");
        }
    }

    public function createForm(int $id, $player) {
        switch($id) {
            case 0:
                $form = $this->formAPI->createCustomForm(function (Player $player, $data){
                    $result = $data[0];
                    if ($result === null) {
                        $this->createForm(0, $player);
                        return true;
                    }
                    switch ($result) {
                        case 0:
                            if(!empty($data[0]) && !empty($data[1])) {
                                if($data[0] == $data[1]) {
                                    $this->registerPlayer($player, $data[0]);
                                } else {
									$this->createForm(0, $player);
								}
                            } else {
                                $this->createForm(0, $player);
                            }
                            return true;
                    }
                });
                $form->setTitle(C::BOLD . "Registration");
                $form->addInput("Password:");
                $form->addInput("Confirm Password:");
                $form->sendToPlayer($player);
                break;
            case 1:
                $form = $this->formAPI->createCustomForm(function (Player $player, $data){
                    $result = $data[0];
                    if ($result === null) {
                        $this->createForm(1, $player);
                        return true;
                    }
                    switch ($result) {
                        case 0:
                            if(!empty($data[0])) {
                                $this->authenticatePlayer($player, $data[0]);
                            } else {
                                $this->createForm(1, $player);
                            }
                            return true;
                    }
                });
                $form->setTitle(C::BOLD . "Autohorization");
                $form->addInput("Password:");
                $form->sendToPlayer($player);
                break;
            case 2:
                $form = $this->formAPI->createCustomForm(function (Player $player, array $data) {
                    $result = $data[0];
                    if ($result === null) {
                        $this->reCreateForm(0, $player);
                        return true;
                    }
                    switch ($result) {
                        case 0:
                            if(!empty($data[0]) && !empty($data[1])) {
                                if($data[0] == $data[1]) {
                                    $this->changePlayerPassword($player, $data[0]);
                                } else {
                                   return $player->sendMessage("> " . C::RED . "The password you entered does not match.");
                                }
                            }
                            return true;
                    }
                });
                $form->setTitle(C::BOLD . "Change Password");
                $form->addInput("New Password");
                $form->addInput("Confirm Password");
                $form->sendToPlayer($player);
                break;
        }
    }

    public function onLogin(PlayerLoginEvent $event) {
        $player = $event->getPlayer();
        $this->getScheduler()->scheduleDelayedTask(new sendFormAuth($this, $player), 160);
    }

    public function onPlayerMove(PlayerMoveEvent $event) {
        if(!$this->isPlayerAuthenticated($event->getPlayer())) {
            $event->setCancelled(true);
        }
    }

    public function onChat(PlayerChatEvent $event) {
        if(!$this->isPlayerAuthenticated($event->getPlayer())) {
            $event->setCancelled(true);
            $event->getPlayer()->sendMessage(C::DARK_BLUE . "> " . C::GREEN . "Please authenticate to play.");
        }
        $recipients = $event->getRecipients();
        foreach($recipients as $key => $recipient) {
            if($recipient instanceof Player) {
                if(!$this->isPlayerAuthenticated($recipient)) {
                        unset($recipients[$key]);
                }
            }
        }
        $event->setRecipients($recipients);
    }

    public function onPlayerCommand(PlayerCommandPreprocessEvent $event) {
        if(!$this->isPlayerAuthenticated($event->getPlayer())) {
            $command = strtolower($event->getMessage());
            if ($command{0} == "/") {
                $event->setCancelled(true);
            $event->getPlayer()->sendMessage(C::DARK_BLUE . "> " . C::GREEN . "Please authenticate to play.");
            }
        }
    }

    public function onPlayerInteract(PlayerInteractEvent $event) {
        if(!$this->isPlayerAuthenticated($event->getPlayer())) {
            $event->setCancelled(true);
            $event->getPlayer()->sendMessage(C::DARK_BLUE . "> " . C::GREEN . "Please authenticate to play.");
        }
    }

    public function onBlockBreak(BlockBreakEvent $event) {
        if(!$this->isPlayerAuthenticated($event->getPlayer())) {
            $event->setCancelled(true);
        }
    }

    public function onEntityDamage(EntityDamageEvent $event) {
        $player = $event->getEntity();
        if($player instanceof Player) {
            if(!$this->isPlayerAuthenticated($player)) {
                $event->setCancelled(true);
            }
        }
        if($event instanceof EntityDamageByEntityEvent) {
            $damager = $event->getDamager();
            if($damager instanceof Player) {
                if(!$this->isPlayerAuthenticated($damager)) {
                    $event->setCancelled(true);
                }
            }
        }
    }

    public function onDropItem(PlayerDropItemEvent $event) {
        if(!$this->isPlayerAuthenticated($event->getPlayer())) {
            $event->setCancelled(true);
            $event->getPlayer()->sendMessage(C::DARK_BLUE . "> " . C::GREEN . "Please authenticate to play.");
        }
    }

    public function onItemConsume(PlayerItemConsumeEvent $event) {
        if(!$this->isPlayerAuthenticated($event->getPlayer())) {
            $event->setCancelled(true);
            $event->getPlayer()->sendMessage(C::DARK_BLUE . "> " . C::GREEN . "Please authenticate to play.");
        }
    }

    public function onCraftItem(CraftItemEvent $event) {
        if(!$this->isPlayerAuthenticated($event->getPlayer())) {
            $event->setCancelled(true);
            $event->getPlayer()->sendMessage(C::DARK_BLUE . "> " . C::GREEN . "Please authenticate to play.");
        }
    }

    public function onAwardAchievement(PlayerAchievementAwardedEvent $event) {
        if(!$this->isPlayerAuthenticated($event->getPlayer())) {
            $event->setCancelled(true);
            $event->getPlayer()->sendMessage(C::DARK_BLUE . "> " . C::GREEN . "Please authenticate to play.");
        }
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool {
        if($command->getName() == "changepassword") {
            $this->createForm(2, $sender);
        }
        return true;
    }
}

class sendFormAuth extends Task {

    public function __construct(PluginBase $owner, Player $player) {
        $this->player = $player;
        $this->owner = $owner;
    }

    public function onRun(int $currentTick){
        $owner = $this->owner;
        $player = $this->player;
        if(!$owner->isPlayerRegistered($player->getName())) {
            $owner->createForm(0, $player);
        } else {
            if(!$owner->isPlayerAuthenticated($player)) {
                $owner->createForm(1, $player);
            } else {
				$player->sendMessage(C::GREEN . "You have been logged in by your IP Address.");
			}
        }
    }
}