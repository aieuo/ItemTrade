<?php

namespace aieuo\itemtrade;

use pocketmine\Player;
use pocketmine\item\Item;
use pocketmine\utils\Config;
use onebone\economyapi\EconomyAPI;

class ItemDataBase {
    public function __construct(Main $owner, Config $items, Config $buylog, Config $selllog) {
        $this->owner = $owner;
        $this->items = $items;
        $this->buylog = $buylog;
        $this->selllog = $selllog;
    }

    public function save() {
        $this->items->save();
        $this->buylog->save();
        $this->selllog->save();
    }

    public function getSell(Item $item) {
        $list = $this->items->get($item->getId().":".$item->getDamage(), ["sell" => [], "buy" => []]);
        if(!isset($list["sell"])) $list["sell"] = [];
        return $list["sell"];
    }


    public function getBuy(Item $item) {
        $list = $this->items->get($item->getId().":".$item->getDamage(), ["sell" => [], "buy" => []]);
        if(!isset($list["buy"])) $list["buy"] = [];
        return $list["buy"];
    }

    public function canSell(Item $item, int $price) {
        $buy = $this->getBuy($item);
        if(!isset($buy[$price])) return false;
        $total = 0;
        foreach($buy[$price] as $count) {
            $total += $count;
        }
        return $item->getCount() <= $total;
    }

    public function canBuy(Item $item, int $price) {
        $sell = $this->getSell($item);
        if(!isset($sell[$price])) return false;
        $total = 0;
        foreach($sell[$price] as $count) {
            $total += $count;
        }
        return $item->getCount() <= $total;
    }

    public function getSellableCount(Item $item, int $price) {
        $buy = $this->getBuy($item);
        if(!isset($buy[$price])) return 0;
        $total = 0;
        foreach($buy[$price] as $count) {
            $total += $count;
        }
        if($item->getCount() <= $total) return $item->getCount();
        return $total;
    }

    public function getBuyableCount(Item $item, int $price) {
        $sell = $this->getSell($item);
        if(!isset($sell[$price])) return 0;
        $total = 0;
        foreach($sell[$price] as $count) {
            $total += $count;
        }
        if($item->getCount() <= $total) return $item->getCount();
        return $total;
    }

    public function addSell(Player $player, Item $item, int $price) {
        $sell = $this->getSell($item);
        $amount = $item->getCount();
        if(isset($sell[$price][$player->getName()])) {
            $amount += $sell[$price][$player->getName()];
        }
        $sell[$price][$player->getName()] = $amount;
        $this->items->setNested($item->getId().":".$item->getDamage().".sell", $sell);
    }

    public function addBuy(Player $player, Item $item, int $price) {
        $buy = $this->getBuy($item);
        $amount = $item->getCount();
        if(isset($buy[$price][$player->getName()])) {
            $amount += $buy[$price][$player->getName()];
        }
        $buy[$price][$player->getName()] = $amount;
        $this->items->setNested($item->getId().":".$item->getDamage().".buy", $buy);
    }

    public function onSell(Player $player, Item $item, int $price) {
        $buy = $this->getBuy($item);
        $amount = $item->getCount();
        if(!isset($buy[$price])) return $amount;
        $buyers = [];
        foreach($buy[$price] as $name => $count) {
            if($amount >= $count) {
                $buyers[$name] = $count;
                $amount -= $count;
            } else {
                $buyers[$name] = $count - $amount;
                $amount = 0;
                break;
            }
            if($amount == 0) break;
        }
        foreach($buyers as $name => $count) {
            $buy[$price][$name] -= $count;
            $log = $this->buylog->get($name, []);
            if(!isset($log[$item->getId().":".$item->getDamage()])) {
                $log[$item->getId().":".$item->getDamage()] = [];
            }
            $log[$item->getId().":".$item->getDamage()][] = ["count" => $count, "price" => $price];
            $this->buylog->set($name, $log);
            if($buy[$price][$name] <= 0) {
                unset($buy[$price][$name]);
            }
        }
        if(empty($buy[$price])) {
            unset($buy[$price]);
        }
        $this->items->setNested($item->getId().":".$item->getDamage().".buy", $buy);
        return $amount;
    }

    public function onBuy(Player $player, Item $item, int $price) {
        $sell = $this->getSell($item);
        $amount = $item->getCount();
        if(!isset($sell[$price])) return $amount;
        $sellers = [];
        foreach($sell[$price] as $name => $count) {
            if($amount >= $count) {
                $sellers[$name] = $count;
                $amount -= $count;
            } else {
                $sellers[$name] = $amount;
                $amount = 0;
                break;
            }
            if($amount == 0) break;
        }
        foreach($sellers as $name => $count) {
            $sell[$price][$name] -= $count;
            $log = $this->selllog->get($name, []);
            if(!isset($log[$item->getId().":".$item->getDamage()])) {
                $log[$item->getId().":".$item->getDamage()] = [];
            }
            $log[$item->getId().":".$item->getDamage()][] = ["count" => $count, "price" => $price];
            $this->selllog->set($name, $log);
            if($sell[$price][$name] <= 0) {
                unset($sell[$price][$name]);
            }
        }
        if(empty($sell[$price])) {
            unset($sell[$price]);
        }
        $this->items->setNested($item->getId().":".$item->getDamage().".sell", $sell);
        return $amount;
    }
}