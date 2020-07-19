<?php

/**
 * @name CustomRecipe
 * @main tjwls012\customrecipe\CustomRecipe
 * @author ["tjwls012"]
 * @version 0.1
 * @api 3.14.0
 * @description License : LGPL 3.0
 */
 
namespace tjwls012\customrecipe;
 
use pocketmine\event\Listener;
use pocketmine\plugin\PluginBase;

use pocketmine\Player;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\command\PluginCommand;

use pocketmine\item\Item;

use pocketmine\block\BlockIds;
use pocketmine\block\BlockFactory;

use pocketmine\level\Level;
use pocketmine\level\Position;

use pocketmine\inventory\Inventory;
use pocketmine\inventory\BaseInventory;
use pocketmine\inventory\ContainerInventory;
use pocketmine\inventory\ShapedRecipe;
use pocketmine\inventory\FurnaceRecipe;
use pocketmine\event\inventory\InventoryCloseEvent;
use pocketmine\event\inventory\InventoryTransactionEvent;

use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\network\mcpe\protocol\types\WindowTypes;
use pocketmine\network\mcpe\protocol\types\RuntimeBlockMapping;
use pocketmine\network\mcpe\protocol\ContainerOpenPacket;
use pocketmine\network\mcpe\protocol\ContainerClosePacket;
use pocketmine\network\mcpe\protocol\InventoryContentPacket;
use pocketmine\network\mcpe\protocol\BlockActorDataPacket;
use pocketmine\network\mcpe\protocol\UpdateBlockPacket;

use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\nbt\tag\DoubleTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\NetworkLittleEndianNBTStream;
use pocketmine\nbt\LittleEndianNBTStream;

use pocketmine\math\Vector3;

use pocketmine\tile\Tile;
use pocketmine\tile\Chest;
use pocketmine\tile\Spawnable;

use pocketmine\utils\Config;

use pocketmine\scheduler\ClosureTask;

class CustomRecipe extends PluginBase implements Listener{

  public static $instance;
  
  public static function getInstance(){
  
    return self::$instance;
  }
  public function onLoad(){
  
    self::$instance = $this;
  }
  public function onEnable(){
  
    $this->getServer()->getPluginManager()->registerEvents($this, $this);
    
    $a = new PluginCommand("craftingrecipe", $this);
    $a->setPermission("op");
    $a->setUsage("/craftingrecipe");
    $a->setDescription("manage crafting recipes");
    $this->getServer()->getCommandMap()->register($this->getDescription()->getName(), $a);
    
    $a = new PluginCommand("smeltingrecipe", $this);
    $a->setPermission("op");
    $a->setUsage("/smeltingrecipe");
    $a->setDescription("manage smelting recipes");
    $this->getServer()->getCommandMap()->register($this->getDescription()->getName(), $a);
    
    @mkdir($this->getDataFolder());
    $this->RecipeData = new Config($this->getDataFolder()."RecipeData.yml", Config::YAML);
    $this->data = $this->RecipeData->getAll();
    
    if(!isset($this->data["crafting"])){
    
      $this->data["crafting"] = [];
      
      $this->save();
    }
    
    if(!isset($this->data["smelting"])){
    
      $this->data["smelting"] = [];
      
      $this->save();
    }
    
    $this->enableRecipe("crafting");
    $this->enableRecipe("smelting");
  }
  public function onCommand(CommandSender $sender, Command $command, string $label, array $array) : bool{
  
    if(!$sender instanceof Player) return true;
    
    $command = $command->getName();
    
    $player = $sender;
    
    if($command === "craftingrecipe" or $command === "smeltingrecipe"){
    
      $type = ($command === "craftingrecipe") ? "crafting" : "smelting";
      
      if(count($array) == 2){
        
        if($array[0] === "add"){
        
          if(!isset($this->data[$type][$array[1]])){
            
            $this->addRecipeGUI($player, $type, $array[1]);
          }
          else{
          
            $player->sendMessage("there is already ".$type." data which was saved as : ".$array[1]);
          }
        }
        elseif($array[0] === "remove"){
        
          if(isset($this->data[$type][$array[1]])){
          
            unset($this->data[$type][$array[1]]);
            
            $this->save();
            
            $player->sendMessage("you removed ".$type." recipe : ".$array[1]);
          }
          else{
          
            $player->sendMessage("there is no ".$type." data which was saved as : ".$array[1]);
          }
        }
        else{
        
          $this->sendInfo($player, $type);
        }
      }
      elseif(count($array) == 1){
      
        if($array[0] == "lists"){
        
          $this->sendLists($player, $type, $this->data[$type]);
        }
        else{
        
          $this->sendInfo($player, $type);
        }
      }
      else{
      
        $this->sendInfo($player, $type);
      }
    }
    
    return true;
  }
  public function onDataReceive(DataPacketReceiveEvent $e){
  
    $player = $e->getPlayer();
    
    $packet = $e->getPacket();
    
    if($packet instanceof ContainerClosePacket){
    
      $inventory = $player->getWindow($packet->windowId);
      
      if($inventory instanceof CraftingRecipeInventory or $inventory instanceof SmeltingRecipeInventory){
      
        $packet = new ContainerClosePacket();
        $packet->windowId = $player->getWindowId($inventory);
        $player->sendDataPacket($packet);
      }
    }
  }
  public function onInventoryTransaction(InventoryTransactionEvent $e){
   
    $transaction = $e->getTransaction();
    
    $source = $transaction->getSource();
    
    if($source instanceof Player){
    
      $player = $source;
      
      foreach($transaction->getInventories() as $inventory){
      
        if($inventory instanceof CraftingRecipeInventory){
        
          foreach($transaction->getActions() as $action){
          
            $source = $action->getSourceItem();
            $target = $action->getTargetItem();
            
            if($source->getNamedTagEntry("customrecipe") !== null or $target->getNamedTagEntry("customrecipe") !== null){
            
              $e->setCancelled(true);
            }
            
            if($source->getNamedTagEntry("crafting") !== null or $target->getNamedTagEntry("crafting") !== null){
            
              $e->setCancelled();
              
              $inventory->onClose($player);
              
              $recipename = $inventory->getRecipeName();
              
              if(isset($this->data["crafting"][$recipename])) return true;
              
              $array = [];
              
              for($i = 1; $i <= 3; $i++){
              
                for($i2 = 1; $i2 <= 3; $i2++){
                
                  $number = (int) ($i * 9) + $i2;
                  
                  $item = $inventory->getItem($number);
                  $nbt = $this->getNBT($item);
                  
                  $array [] = $nbt;
                }
              }
              
              $output_item = $inventory->getItem(25);
              $output = $this->getNBT($output_item);
              
              $this->data["crafting"][$recipename]["ingredient"] = $array;
              $this->data["crafting"][$recipename]["production"] = $output;
              
              $this->save();
              
              $player->sendMessage("you added crafting recipe : ".$recipename);
            }
          }
        }
        elseif($inventory instanceof SmeltingRecipeInventory){
        
          foreach($transaction->getActions() as $action){
          
            $source = $action->getSourceItem();
            $target = $action->getTargetItem();
            
            if($source->getNamedTagEntry("customrecipe") !== null or $target->getNamedTagEntry("customrecipe") !== null){
            
              $e->setCancelled(true);
            }
            
            if($source->getNamedTagEntry("smelting") !== null or $target->getNamedTagEntry("smelting") !== null){
            
              $e->setCancelled();
              
              $inventory->onClose($player);
              
              $recipename = $inventory->getRecipeName();
              
              if(isset($this->data["smelting"][$recipename])) return true;
              
              $input_item = $inventory->getItem(10);
              $input = $this->getNBT($input_item);
              
              $output_item = $inventory->getItem(16);
              $output = $this->getNBT($output_item);
              
              $this->data["smelting"][$recipename]["ingredient"] = $input;
              $this->data["smelting"][$recipename]["production"] = $output;
              
              $this->save();
              
              $player->sendMessage("you added smelting recipe : ".$recipename);
            }
          }
        }
      }
    }
  }            
  public function sendInfo(Player $player, string $type){
  
    $player->sendMessage("/".$type."recipe add <name>");
    $player->sendMessage("/".$type."recipe remove <name>");
    $player->sendMessage("/".$type."recipe lists");
  }
  public function sendLists(Player $player, string $type, $data){
  
    if(count($data) > 0){
    
      foreach($data as $name => $s){
      
        $player->sendMessage($type." : ".$name);
      }
    }
    else{
    
      $player->sendMessage("there is no ".$type." data");
    }
  }
  public function addRecipeGUI(Player $player, string $type, string $recipename){
  
    if($type === "crafting"){
    
      $player->addWindow(new CraftingRecipeInventory($this, [], $player->asVector3(), 54, "Crafting Recipe GUI", $recipename));
    }
    elseif($type === "smelting"){
    
      $player->addWindow(new SmeltingRecipeInventory($this, [], $player->asVector3(), 27, "Smelting Recipe GUI", $recipename));
    }
  }
  public function enableRecipe(string $type){
    
    if($type === "crafting" and count($this->data["crafting"]) > 0){
      
      foreach($this->data["crafting"] as $d){
        
        $input = $d["ingredient"];
        $output = $d["production"];
        
        $recipe = new ShapedRecipe(
          [
            "ABC",
            "DEF",
            "GHI"
          ],
          [
            "A" => $this->getItem($input[0]),
            "B" => $this->getItem($input[1]),
            "C" => $this->getItem($input[2]),
            "D" => $this->getItem($input[3]),
            "E" => $this->getItem($input[4]),
            "F" => $this->getItem($input[5]),
            "G" => $this->getItem($input[6]),
            "H" => $this->getItem($input[7]),
            "I" => $this->getItem($input[8])
          ],
          [
            $this->getItem($output)
          ]
        );
        
        $this->getServer()->getCraftingManager()->registerRecipe($recipe);
      }
    }
    elseif($type === "smelting" and count($this->data["smelting"]) > 0){
    
      foreach($this->data["smelting"] as $d){
      
        $input = $d["ingredient"];
        $output = $d["production"];
        
        $recipe = new FurnaceRecipe($this->getItem($output), $this->getItem($input));
        
        $this->getServer()->getCraftingManager()->registerRecipe($recipe);
      }
    }
  }
  public function getNBT($item){
  
    return $item->jsonSerialize();
  }
  public function getItem($item){
  
    return Item::jsonDeserialize($item);
  }
  public function save(){
  
    $this->RecipeData->setAll($this->data);
    $this->RecipeData->save();
  }
}
class CraftingRecipeInventory extends BaseInventory{

  private $plugin;
  
  private $vector;
  private $recipename = "";
  
  protected $size;
  protected $title;
  
  public function __construct(CustomRecipe $plugin, array $items, Vector3 $vector3, int $size = null, string $title = "", string $recipename){
  
    $this->plugin = $plugin;
    
    $this->size = $size;
    $this->title = $title;
    
    $this->recipename = $recipename;
    
    parent::__construct([], 54, $title);
  }
  public function onOpen(Player $player) : void{
  
    BaseInventory::onOpen($player);
    
    $this->setVector3($player->add(0, 5, 0)->floor());
    
    $x = $this->getVector3()->x;
    $y = $this->getVector3()->y;
    $z = $this->getVector3()->z;
    
    for($i = 0; $i < 2; $i++){
    
      $packet = new UpdateBlockPacket();
      $packet->x = $x + $i;
      $packet->y = $y;
      $packet->z = $z;
      $packet->flags = UpdateBlockPacket::FLAG_NONE;
      $packet->blockRuntimeId = BlockFactory::toStaticRuntimeId(BlockIds::CHEST);
      $player->sendDataPacket($packet);
      
      $nbt = new CompoundTag("", [
                      new StringTag(Tile::TAG_ID, Tile::CHEST),
                      new IntTag(Tile::TAG_X, $x),
                      new IntTag(Tile::TAG_Y, $y),
                      new IntTag(Tile::TAG_Z, $z),
                      new IntTag(Chest::TAG_PAIRX, $x + (1 - $i)),
                      new IntTag(Chest::TAG_PAIRZ, $z),
                      new StringTag(Chest::TAG_CUSTOM_NAME, $this->getName())
      ]);
      
      $packet = new BlockActorDataPacket();
      $packet->x = $x + 1;
      $packet->y = $y;
      $packet->z = $z;
      $packet->namedtag = (new NetworkLittleEndianNBTStream())->write($nbt);
      $player->sendDataPacket($packet);
    }
    
    $this->plugin->getScheduler()->scheduleDelayedTask(new ClosureTask(function(int $time) use ($player, $x, $y, $z) : void{ //source from alvin0319
    
      $packet = new ContainerOpenPacket();
      $packet->x = $x;
      $packet->y = $y;
      $packet->z = $z;
      $packet->windowId = $player->getWindowId($this);
      $player->sendDataPacket($packet);
      
      $stained_glass_yellow = Item::get(241, 4, 1);
      $stained_glass_yellow->setNamedTagEntry(new StringTag("customrecipe", "crafting"));
      $stained_glass_yellow->setCustomName("§r§f");
      
      $this->setItem(0, $stained_glass_yellow);
      $this->setItem(1, $stained_glass_yellow);
      $this->setItem(2, $stained_glass_yellow);
      $this->setItem(3, $stained_glass_yellow);
      $this->setItem(4, $stained_glass_yellow);
      $this->setItem(9, $stained_glass_yellow);
      $this->setItem(13, $stained_glass_yellow);
      $this->setItem(18, $stained_glass_yellow);
      $this->setItem(22, $stained_glass_yellow);
      $this->setItem(27, $stained_glass_yellow);
      $this->setItem(31, $stained_glass_yellow);
      $this->setItem(36, $stained_glass_yellow);
      $this->setItem(37, $stained_glass_yellow);
      $this->setItem(38, $stained_glass_yellow);
      $this->setItem(39, $stained_glass_yellow);
      $this->setItem(40, $stained_glass_yellow);
      
      $stained_glass_lime = Item::get(241, 5, 1);
      $stained_glass_lime->setNamedTagEntry(new StringTag("customrecipe", "crafting"));
      $stained_glass_lime->setCustomName("§r§f");
      
      $this->setItem(15, $stained_glass_lime);
      $this->setItem(16, $stained_glass_lime);
      $this->setItem(17, $stained_glass_lime);
      $this->setItem(24, $stained_glass_lime);
      $this->setItem(26, $stained_glass_lime);
      $this->setItem(33, $stained_glass_lime);
      $this->setItem(34, $stained_glass_lime);
      $this->setItem(35, $stained_glass_lime);
      
      $paper = Item::get(339, 0, 1);
      $paper->setNamedTagEntry(new StringTag("customrecipe", "crafting"));
      $paper->setCustomName("§r§fExplanation");
      $paper->setLore(["§fput the ingredients on left.\nput the product on right."]);
      
      $this->setItem(52, $paper);
      
      $emerald = Item::get(388, 0, 1);
      $emerald->setNamedTagEntry(new StringTag("customrecipe", "crafting"));
      $emerald->setNamedTagEntry(new StringTag("crafting", "confirm_button"));
      $emerald->setCustomName("§r§fConfirm");
      $emerald->setLore(["§fcheck that you did well.\nif you interact with this item, new recipe is registered."]);
      
      $this->setItem(53, $emerald);
      
      $this->sendContents($player);
      
    }), 10);
  }
  public function onClose(Player $player):void{
  
    BaseInventory::onClose($player);

    $x = $this->getVector3()->x;
    $y = $this->getVector3()->y;
    $z = $this->getVector3()->z;
    
    for($i = 0; $i < 2; $i++){
    
      $packet = new UpdateBlockPacket();
      $packet->x = $x + $i;
      $packet->y = $y;
      $packet->z = $z;
      $packet->flags = UpdateBlockPacket::FLAG_NONE;
      $packet->blockRuntimeId = BlockFactory::toStaticRuntimeId($player->getLevel()->getBlock($this->getVector3())->getId(), $player->getLevel()->getBlock($this->getVector3())->getDamage());
      $player->sendDataPacket($packet);
    }
  }
  public function getNetworkType() : int{

    return WindowTypes::CONTAINER;
  }
  public function getName() : string{

    return $this->title;
  }
  public function getDefaultSize() : int{

    return $this->size;
  }
  public function setVector3(Vector3 $vector3) : void{
  
    $this->vector = $vector3;
  }
  public function getVector3() : Vector3{
  
    return $this->vector;
  }
  public function getRecipeName() : string{
  
    return $this->recipename;
  }
}
class SmeltingRecipeInventory extends BaseInventory{

  private $plugin;
  
  private $vector;
  private $recipename = "";
  
  protected $size;
  protected $title;
  
  public function __construct(CustomRecipe $plugin, array $items, Vector3 $vector3, int $size = null, string $title = "", string $recipename){
  
    $this->plugin = $plugin;
    
    $this->size = $size;
    $this->title = $title;
    
    $this->recipename = $recipename;
    
    parent::__construct([], 27, $title);
  }
  public function onOpen(Player $player) : void{
  
    BaseInventory::onOpen($player);
    
    $this->setVector3($player->add(0, 5, 0)->floor());
    
    $x = $this->getVector3()->x;
    $y = $this->getVector3()->y;
    $z = $this->getVector3()->z;
    
    $packet = new UpdateBlockPacket();
    $packet->x = $x;
    $packet->y = $y;
    $packet->z = $z;
    $packet->flags = UpdateBlockPacket::FLAG_NONE;
    $packet->blockRuntimeId = BlockFactory::toStaticRuntimeId(BlockIds::CHEST);
    $player->sendDataPacket($packet);
     
    $nbt = new CompoundTag("", [
                    new StringTag(Tile::TAG_ID, Tile::CHEST),
                    new IntTag(Tile::TAG_X, $x),
                    new IntTag(Tile::TAG_Y, $y),
                    new IntTag(Tile::TAG_Z, $z),
                    new StringTag(Chest::TAG_CUSTOM_NAME, $this->getName())
    ]);
    
    $packet = new BlockActorDataPacket();
    $packet->x = $x;
    $packet->y = $y;
    $packet->z = $z;
    $packet->namedtag = (new NetworkLittleEndianNBTStream())->write($nbt);
    $player->sendDataPacket($packet);
    
    $packet = new ContainerOpenPacket();
    $packet->x = $x;
    $packet->y = $y;
    $packet->z = $z;
    $packet->windowId = $player->getWindowId($this);
    $player->sendDataPacket($packet);
    
    $stained_glass_red = Item::get(241, 14, 1);
    $stained_glass_red->setNamedTagEntry(new StringTag("customrecipe", "crafting"));
    $stained_glass_red->setCustomName("§r§f");
    
    $this->setItem(0, $stained_glass_red);
    $this->setItem(1, $stained_glass_red);
    $this->setItem(2, $stained_glass_red);
    $this->setItem(9, $stained_glass_red);
    $this->setItem(11, $stained_glass_red);
    $this->setItem(18, $stained_glass_red);
    $this->setItem(19, $stained_glass_red);
    $this->setItem(20, $stained_glass_red);
    
    $stained_glass_blue = Item::get(241, 11, 1);
    $stained_glass_blue->setNamedTagEntry(new StringTag("customrecipe", "crafting"));
    $stained_glass_blue->setCustomName("§r§f");
    
    $this->setItem(6, $stained_glass_blue);
    $this->setItem(7, $stained_glass_blue);
    $this->setItem(8, $stained_glass_blue);
    $this->setItem(15, $stained_glass_blue);
    $this->setItem(17, $stained_glass_blue);
    $this->setItem(24, $stained_glass_blue);
    $this->setItem(25, $stained_glass_blue);
    $this->setItem(26, $stained_glass_blue);
    
    $paper = Item::get(339, 0, 1);
    $paper->setNamedTagEntry(new StringTag("customrecipe", "crafting"));
    $paper->setCustomName("§r§fExplanation");
    $paper->setLore(["§fput the ingredient on left.\nput the product on right."]);
    
    $this->setItem(4, $paper);
    
    $emerald = Item::get(388, 0, 1);
    $emerald->setNamedTagEntry(new StringTag("customrecipe", "crafting"));
    $emerald->setNamedTagEntry(new StringTag("smelting", "confirm_button"));
    $emerald->setCustomName("§r§fConfirm");
    $emerald->setLore(["§fcheck that you did well.\nif you interact with this item, new recipe is registered."]);
    
    $this->setItem(22, $emerald);
    
   $this->sendContents($player);
  }
  public function onClose(Player $player):void{
  
    BaseInventory::onClose($player);

    $x = $this->getVector3()->x;
    $y = $this->getVector3()->y;
    $z = $this->getVector3()->z;
    
    $packet = new UpdateBlockPacket();
    $packet->x = $x;
    $packet->y = $y;
    $packet->z = $z;
    $packet->flags = UpdateBlockPacket::FLAG_NONE;
    $packet->blockRuntimeId = BlockFactory::toStaticRuntimeId($player->getLevel()->getBlock($this->getVector3())->getId(), $player->getLevel()->getBlock($this->getVector3())->getDamage());
    $player->sendDataPacket($packet);
  }
  public function getNetworkType() : int{

    return WindowTypes::CONTAINER;
  }
  public function getName() : string{

    return $this->title;
  }
  public function getDefaultSize() : int{

    return $this->size;
  }
  public function setVector3(Vector3 $vector3) : void{
  
    $this->vector = $vector3;
  }
  public function getVector3() : Vector3{
  
    return $this->vector;
  }
  public function getRecipeName() : string{
  
    return $this->recipename;
  }
}
