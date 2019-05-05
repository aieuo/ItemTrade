<?php

namespace aieuo\itemtrade;

use pocketmine\event\Listener;
use pocketmine\plugin\PluginBase;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\network\mcpe\protocol\ModalFormResponsePacket;
use pocketmine\network\mcpe\protocol\ModalFormRequestPacket;
use pocketmine\utils\Config;
use pocketmine\item\Item;
use pocketmine\event\player\PlayerJoinEvent;
use onebone\economyapi\EconomyAPI;

class Main extends PluginBase implements Listener {
    public function onEnable() {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $items = new Config($this->getDataFolder()."items.yml", Config::YAML, []);
        $this->buylog = new Config($this->getDataFolder()."buylog.yml", Config::YAML, []);
        $this->selllog = new Config($this->getDataFolder()."selllog.yml", Config::YAML, []);
        $this->db = new ItemDataBase($this, $items, $this->buylog, $this->selllog);
    }

    public function onDisable() {
        $this->db->save();
    }

    public function join(PlayerJoinEvent $event) {
        $player = $event->getPlayer();
        if($this->buylog->exists($player->getName())) {
            $log = $this->buylog->get($player->getName());
            foreach($log as $id => $values) {
                foreach($values as $value) {
                    $player->sendMessage("$".$value["price"]."で注文した".$id."が".$value["count"]."個購入できました");
                    $ids = explode(":", $id);
                    $item = Item::get($ids[0], $ids[1], $value["count"]);
                    $player->getInventory()->addItem($item);
                }
            }
            $this->buylog->remove($player->getName());
        }
        if($this->selllog->exists($player->getName())) {
            $log = $this->selllog->get($player->getName());
            $price = 0;
            foreach($log as $id => $values) {
                foreach($values as $value) {
                    $player->sendMessage("$".$value["price"]."で注文した".$id."が".$value["count"]."個売却できました");
                    $price += $value["count"] * $value["price"];
                }
            }
            EconomyAPI::getInstance()->addMoney($player->getName(), $price);
            $this->selllog->remove($player->getName());
        }
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool {
        if(!$command->testPermission($sender)) return true;
        $item = $sender->getInventory()->getItemInHand();
        $form = [
            "type" => "form",
            "title" => "選択",
            "content" => "§7ボタンを押してください",
            "buttons" => [
                ["text" => "売る"],
                ["text" => "買う"],
                ["text" => "キャンセル"]
            ]
        ];
        $this->sendForm($sender, $form, [$this, "onMenu"]);
        return true;
    }

    public function onMenu($player, $data) {
        if($data === null) return;
        $item = $player->getInventory()->getItemInHand();
        switch ($data) {
            case 0:
                if($item->getId() === 0) {
                    $player->sendMessage("そのアイテムは売れません");
                    return;
                }
                $buylist = $this->db->getBuy($item);
                $buylist = array_map(function($value) { return array_sum($value); }, $buylist);
                krsort($buylist);
                $buylist = array_slice($buylist, 0, 10, true);
                $list = [];
                foreach($buylist as $price => $value) {
                    $list[] = "$".$price." : ".$value."個";
                }
                $form = [
                    "type" => "custom_form",
                    "title" => "売る",
                    "content" => [
                        [
                            "type" => "label",
                            "text" => $item->getId().":".$item->getDamage()
                        ],
                        [
                            "type" => "input",
                            "text" => "個数",
                            "placeholder" => "1以上の数字で"
                        ],
                        [
                            "type" => "input",
                            "text" => "値段",
                            "placeholder" => "1以上の数字で"
                        ],
                        [
                            "type" => "label",
                            "text" => "------買い注文------\n".implode("\n", $list)
                        ]
                    ]
                ];
                $this->sendForm($player, $form, [$this, "onSell"], new Item($item->getId(), $item->getDamage()));
                break;
            case 1:
                $selllist = $this->db->getSell($item);
                $selllist = array_map(function($value) { return array_sum($value); }, $selllist);
                ksort($selllist);
                $selllist = array_slice($selllist, 0, 10, true);
                $list = [];
                foreach($selllist as $price => $value) {
                    $list[] = "$".$price." : ".$value."個";
                }
                $form = [
                    "type" => "custom_form",
                    "title" => "買う",
                    "content" => [
                        [
                            "type" => "input",
                            "text" => "id",
                            "placeholder" => "id:damage",
                            "default" => $item->getId().":".$item->getDamage()
                        ],
                        [
                            "type" => "input",
                            "text" => "個数",
                            "placeholder" => "1以上の数字で"
                        ],
                        [
                            "type" => "input",
                            "text" => "値段",
                            "placeholder" => "1以上の数字で"
                        ],
                        [
                            "type" => "label",
                            "text" => "------売り注文------\n".implode("\n", $list)
                        ]
                    ]
                ];
                $this->sendForm($player, $form, [$this, "onBuy"], new Item($item->getId(), $item->getDamage()));
                break;
        }
    }

    public function onSell($player, $data, $item) {
        if($data === null) return;
        $amount = (int)$data[1];
        $price = (int)$data[2];
        if($amount <= 0) {
            $player->sendMessage("個数は1以上で指定してください");
            return;
        }
        if($price <= 0) {
            $player->sendMessage("値段は1以上で指定してください");
            return;
        }
        $item->setCount($amount);
        if(!$player->getInventory()->contains($item)) {
            $player->sendMessage("アイテムを必要な数持っていません");
            return;
        }
        $sellable = $this->db->getSellableCount($item, $price);
        $form = [
            "type" => "modal",
            "title" => "売却確認",
            "content" => "$".$price."で".$item->getId().":".$item->getDamage()."を本当に売りますか?\n".$amount."個中".$sellable."個今すぐ売ることができます",
            "button1" => "売る",
            "button2" => "キャンセル"
        ];
        $this->sendForm($player, $form, [$this, "onConfirmSell"], $item, $price);
    }

    public function onConfirmSell($player, $data, $item, $price) {
        if($data === null) return;
        if(!$data) {
            $player->sendMessage("キャンセルしました");
            return;
        }
        $remain = $this->db->onSell($player, $item, $price);
        $player->getInventory()->removeItem($item);
        $player->sendMessage(($item->getCount() - $remain)."個売りました");
        EconomyAPI::getInstance()->addMoney($player->getName(), ($item->getCount() - $remain) * $price);
        if($remain !== 0) {
            $item->setCount($remain);
            $this->db->addSell($player, $item, $price);
        }
    }

    public function onBuy($player, $data, $item) {
        if($data === null) return;
        $amount = (int)$data[1];
        $price = (int)$data[2];
        if($amount <= 0) {
            $player->sendMessage("個数は1以上で指定してください");
            return;
        }
        if($price <= 0) {
            $player->sendMessage("値段は1以上で指定してください");
            return;
        }
        $item->setCount($amount);
        if(EconomyAPI::getInstance()->mymoney($player->getName()) < $price * $amount) {
            $player->sendMessage("お金が足りません");
            return;
        }
        $buyable = $this->db->getBuyableCount($item, $price);
        $form = [
            "type" => "modal",
            "title" => "購入確認",
            "content" => "$".$price."で".$item->getId().":".$item->getDamage()."を本当に買いますか?\n".$amount."個中".$buyable."個今すぐ買うことができます",
            "button1" => "買う",
            "button2" => "キャンセル"
        ];
        $this->sendForm($player, $form, [$this, "onConfirmBuy"], $item, $price);
    }

    public function onConfirmBuy($player, $data, $item, $price) {
        if($data === null) return;
        if(!$data) {
            $player->sendMessage("キャンセルしました");
            return;
        }
        $remain = $this->db->onBuy($player, $item, $price);
        $player->sendMessage(($item->getCount() - $remain)."個買いました");
        EconomyAPI::getInstance()->reduceMoney($player->getName(), $item->getCount() * $price);
        if($item->getCount() - $remain !== 0) {
            $item2 = Item::get($item->getId(), $item->getDamage(), $item->getCount() - $remain);
            $player->getInventory()->addItem($item2);
        }
        if($remain !== 0) {
            $item->setCount($remain);
            $this->db->addBuy($player, $item, $price);
        }
    }















    public function encodeJson($data){
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_BIGINT_AS_STRING | JSON_UNESCAPED_UNICODE);
        return $json;
    }

    public function sendForm($player, $form, $callable = null, ...$datas) {
        while(true) {
            $id = mt_rand(0, 999999999);
            if(!isset($this->forms[$id])) break;
        }
        $this->forms[$id] = [$callable, $datas];
        $pk = new ModalFormRequestPacket();
        $pk->formId = $id;
        $pk->formData = $this->encodeJson($form);
        $player->dataPacket($pk);
    }

    public function Receive(DataPacketReceiveEvent $event){
        $pk = $event->getPacket();
        $player = $event->getPlayer();
        if($pk instanceof ModalFormResponsePacket){
            if(isset($this->forms[$pk->formId])) {
                $json = str_replace([",]",",,"], [",\"\"]",",\"\","], $pk->formData);
                $data = json_decode($json);
                if(is_callable($this->forms[$pk->formId][0])) {
                    call_user_func_array($this->forms[$pk->formId][0], array_merge([$player, $data], $this->forms[$pk->formId][1]));
                }
                unset($this->forms[$pk->formId]);
            }
        }
    }
}