<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
*/

declare(strict_types=1);

namespace pocketmine\network\mcpe;

use pocketmine\utils\Binary;

use pocketmine\block\BlockIds;
use pocketmine\entity\Attribute;
use pocketmine\entity\Entity;
use pocketmine\item\Durable;
use pocketmine\item\Item;
use pocketmine\item\ItemFactory;
use pocketmine\item\ItemIds;
use pocketmine\math\Vector3;
use pocketmine\nbt\LittleEndianNBTStream;
use pocketmine\nbt\NetworkLittleEndianNBTStream;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\NamedTag;
use pocketmine\network\mcpe\convert\ItemTranslator;
use pocketmine\network\mcpe\convert\ItemTypeDictionary;
use pocketmine\network\mcpe\convert\RuntimeBlockMapping;
use pocketmine\network\mcpe\protocol\types\CommandOriginData;
use pocketmine\network\mcpe\protocol\types\EntityLink;
use pocketmine\network\mcpe\protocol\types\GameRuleType;
use pocketmine\network\mcpe\protocol\types\PersonaPieceTintColor;
use pocketmine\network\mcpe\protocol\types\PersonaSkinPiece;
use pocketmine\network\mcpe\protocol\types\SkinAnimation;
use pocketmine\network\mcpe\protocol\types\SkinData;
use pocketmine\network\mcpe\protocol\types\SkinImage;
use pocketmine\network\mcpe\protocol\types\StructureEditorData;
use pocketmine\network\mcpe\protocol\types\StructureSettings;
use pocketmine\utils\BinaryStream;
use pocketmine\utils\UUID;
use function assert;
use function count;
use function strlen;

class NetworkBinaryStream extends BinaryStream{

	private const DAMAGE_TAG = "Damage"; //TAG_Int
	private const DAMAGE_TAG_CONFLICT_RESOLUTION = "___Damage_ProtocolCollisionResolution___";
	private const PM_META_TAG = "___Meta___";

	public function getString() : string{
		return $this->get($this->getUnsignedVarInt());
	}

	public function putString(string $v) : void{
		$this->putUnsignedVarInt(strlen($v));
		($this->buffer .= $v);
	}

	public function getUUID() : UUID{
		//This is actually two little-endian longs: UUID Most followed by UUID Least
		$part1 = ((\unpack("V", $this->get(4))[1] << 32 >> 32));
		$part0 = ((\unpack("V", $this->get(4))[1] << 32 >> 32));
		$part3 = ((\unpack("V", $this->get(4))[1] << 32 >> 32));
		$part2 = ((\unpack("V", $this->get(4))[1] << 32 >> 32));

		return new UUID($part0, $part1, $part2, $part3);
	}

	public function putUUID(UUID $uuid) : void{
		($this->buffer .= (\pack("V", $uuid->getPart(1))));
		($this->buffer .= (\pack("V", $uuid->getPart(0))));
		($this->buffer .= (\pack("V", $uuid->getPart(3))));
		($this->buffer .= (\pack("V", $uuid->getPart(2))));
	}

	public function getSkin() : SkinData{
		$skinId = $this->getString();
		$skinPlayFabId = $this->getString();
		$skinResourcePatch = $this->getString();
		$skinData = $this->getSkinImage();
		$animationCount = ((\unpack("V", $this->get(4))[1] << 32 >> 32));
		$animations = [];
		for($i = 0; $i < $animationCount; ++$i){
			$skinImage = $this->getSkinImage();
			$animationType = ((\unpack("V", $this->get(4))[1] << 32 >> 32));
			$animationFrames = ((\unpack("g", $this->get(4))[1]));
			$expressionType = ((\unpack("V", $this->get(4))[1] << 32 >> 32));
			$animations[] = new SkinAnimation($skinImage, $animationType, $animationFrames, $expressionType);
		}
		$capeData = $this->getSkinImage();
		$geometryData = $this->getString();
		$geometryDataVersion = $this->getString();
		$animationData = $this->getString();
		$capeId = $this->getString();
		$fullSkinId = $this->getString();
		$armSize = $this->getString();
		$skinColor = $this->getString();
		$personaPieceCount = ((\unpack("V", $this->get(4))[1] << 32 >> 32));
		$personaPieces = [];
		for($i = 0; $i < $personaPieceCount; ++$i){
			$pieceId = $this->getString();
			$pieceType = $this->getString();
			$packId = $this->getString();
			$isDefaultPiece = (($this->get(1) !== "\x00"));
			$productId = $this->getString();
			$personaPieces[] = new PersonaSkinPiece($pieceId, $pieceType, $packId, $isDefaultPiece, $productId);
		}
		$pieceTintColorCount = ((\unpack("V", $this->get(4))[1] << 32 >> 32));
		$pieceTintColors = [];
		for($i = 0; $i < $pieceTintColorCount; ++$i){
			$pieceType = $this->getString();
			$colorCount = ((\unpack("V", $this->get(4))[1] << 32 >> 32));
			$colors = [];
			for($j = 0; $j < $colorCount; ++$j){
				$colors[] = $this->getString();
			}
			$pieceTintColors[] = new PersonaPieceTintColor(
				$pieceType,
				$colors
			);
		}
		$premium = (($this->get(1) !== "\x00"));
		$persona = (($this->get(1) !== "\x00"));
		$capeOnClassic = (($this->get(1) !== "\x00"));
		$isPrimaryUser = (($this->get(1) !== "\x00"));

		return new SkinData($skinId, $skinPlayFabId, $skinResourcePatch, $skinData, $animations, $capeData, $geometryData, $geometryDataVersion, $animationData, $capeId, $fullSkinId, $armSize, $skinColor, $personaPieces, $pieceTintColors, true, $premium, $persona, $capeOnClassic, $isPrimaryUser);
	}

	/**
	 * @return void
	 */
	public function putSkin(SkinData $skin){
		$this->putString($skin->getSkinId());
		$this->putString($skin->getPlayFabId());
		$this->putString($skin->getResourcePatch());
		$this->putSkinImage($skin->getSkinImage());
		($this->buffer .= (\pack("V", count($skin->getAnimations()))));
		foreach($skin->getAnimations() as $animation){
			$this->putSkinImage($animation->getImage());
			($this->buffer .= (\pack("V", $animation->getType())));
			($this->buffer .= (\pack("g", $animation->getFrames())));
			($this->buffer .= (\pack("V", $animation->getExpressionType())));
		}
		$this->putSkinImage($skin->getCapeImage());
		$this->putString($skin->getGeometryData());
		$this->putString($skin->getGeometryDataEngineVersion());
		$this->putString($skin->getAnimationData());
		$this->putString($skin->getCapeId());
		$this->putString($skin->getFullSkinId());
		$this->putString($skin->getArmSize());
		$this->putString($skin->getSkinColor());
		($this->buffer .= (\pack("V", count($skin->getPersonaPieces()))));
		foreach($skin->getPersonaPieces() as $piece){
			$this->putString($piece->getPieceId());
			$this->putString($piece->getPieceType());
			$this->putString($piece->getPackId());
			($this->buffer .= ($piece->isDefaultPiece() ? "\x01" : "\x00"));
			$this->putString($piece->getProductId());
		}
		($this->buffer .= (\pack("V", count($skin->getPieceTintColors()))));
		foreach($skin->getPieceTintColors() as $tint){
			$this->putString($tint->getPieceType());
			($this->buffer .= (\pack("V", count($tint->getColors()))));
			foreach($tint->getColors() as $color){
				$this->putString($color);
			}
		}
		($this->buffer .= ($skin->isPremium() ? "\x01" : "\x00"));
		($this->buffer .= ($skin->isPersona() ? "\x01" : "\x00"));
		($this->buffer .= ($skin->isPersonaCapeOnClassic() ? "\x01" : "\x00"));
		($this->buffer .= ($skin->isPrimaryUser() ? "\x01" : "\x00"));
	}

	private function getSkinImage() : SkinImage{
		$width = ((\unpack("V", $this->get(4))[1] << 32 >> 32));
		$height = ((\unpack("V", $this->get(4))[1] << 32 >> 32));
		$data = $this->getString();
		return new SkinImage($height, $width, $data);
	}

	private function putSkinImage(SkinImage $image) : void{
		($this->buffer .= (\pack("V", $image->getWidth())));
		($this->buffer .= (\pack("V", $image->getHeight())));
		$this->putString($image->getData());
	}

	public function getItemStackWithoutStackId() : Item{
		return $this->getItemStack(function() : void{
			//NOOP
		});
	}

	public function putItemStackWithoutStackId(Item $item) : void{
		$this->putItemStack($item, function() : void{
			//NOOP
		});
	}

	/**
	 * @phpstan-param \Closure(NetworkBinaryStream) : void $readExtraCrapInTheMiddle
	 */
	public function getItemStack(\Closure $readExtraCrapInTheMiddle) : Item{
		$netId = $this->getVarInt();
		if($netId === 0){
			return ItemFactory::get(0, 0, 0);
		}

		$cnt = ((\unpack("v", $this->get(2))[1]));
		$netData = $this->getUnsignedVarInt();

		[$id, $meta] = ItemTranslator::getInstance()->fromNetworkId($netId, $netData);

		$readExtraCrapInTheMiddle($this);

		$this->getVarInt();

		$extraData = new NetworkBinaryStream($this->getString());
		return (static function() use ($extraData, $netId, $id, $meta, $cnt) : Item{
			$nbtLen = $extraData->getLShort();

			/** @var CompoundTag|null $nbt */
			$nbt = null;
			if($nbtLen === 0xffff){
				$nbtDataVersion = $extraData->getByte();
				if($nbtDataVersion !== 1){
					throw new \UnexpectedValueException("Unexpected NBT data version $nbtDataVersion");
				}
				$decodedNBT = (new LittleEndianNBTStream())->read($extraData->buffer, false, $extraData->offset, 512);
				if(!($decodedNBT instanceof CompoundTag)){
					throw new \UnexpectedValueException("Unexpected root tag type for itemstack");
				}
				$nbt = $decodedNBT;
			}elseif($nbtLen !== 0){
				throw new \UnexpectedValueException("Unexpected fake NBT length $nbtLen");
			}

			//TODO
			for($i = 0, $canPlaceOn = $extraData->getLInt(); $i < $canPlaceOn; ++$i){
				$extraData->get($extraData->getLShort());
			}

			//TODO
			for($i = 0, $canDestroy = $extraData->getLInt(); $i < $canDestroy; ++$i){
				$extraData->get($extraData->getLShort());
			}

			if($netId === ItemTypeDictionary::getInstance()->fromStringId("minecraft:shield")){
				$extraData->getLLong(); //"blocking tick" (ffs mojang)
			}

			if(!$extraData->feof()){
				throw new \UnexpectedValueException("Unexpected trailing extradata for network item $netId");
			}

			if($nbt !== null){
				if($nbt->hasTag(self::DAMAGE_TAG, IntTag::class)){
					$meta = $nbt->getInt(self::DAMAGE_TAG);
					$nbt->removeTag(self::DAMAGE_TAG);
					if(($conflicted = $nbt->getTag(self::DAMAGE_TAG_CONFLICT_RESOLUTION)) !== null){
						$nbt->removeTag(self::DAMAGE_TAG_CONFLICT_RESOLUTION);
						$conflicted->setName(self::DAMAGE_TAG);
						$nbt->setTag($conflicted);
					}elseif($nbt->count() === 0){
						$nbt = null;
					}
				}elseif(($metaTag = $nbt->getTag(self::PM_META_TAG)) instanceof IntTag){
					//TODO HACK: This foul-smelling code ensures that we can correctly deserialize an item when the
					//client sends it back to us, because as of 1.16.220, blockitems quietly discard their metadata
					//client-side. Aside from being very annoying, this also breaks various server-side behaviours.
					$meta = $metaTag->getValue();
					$nbt->removeTag(self::PM_META_TAG);
					if($nbt->count() === 0){
						$nbt = null;
					}
				}
			}
			return ItemFactory::get($id, $meta, $cnt, $nbt);
		})();
	}

	/**
	 * @phpstan-param \Closure(NetworkBinaryStream) : void $writeExtraCrapInTheMiddle
	 */
	public function putItemStack(Item $item, \Closure $writeExtraCrapInTheMiddle) : void{
		if($item->getId() === 0){
			$this->putVarInt(0);

			return;
		}

		$coreData = $item->getDamage();
		[$netId, $netData] = ItemTranslator::getInstance()->toNetworkId($item->getId(), $coreData);

		$this->putVarInt($netId);
		($this->buffer .= (\pack("v", $item->getCount())));
		$this->putUnsignedVarInt($netData);

		$writeExtraCrapInTheMiddle($this);

		$blockRuntimeId = 0;
		$isBlockItem = $item->getId() < 256;
		if($isBlockItem){
			$block = $item->getBlock();
			if($block->getId() !== BlockIds::AIR){
				$blockRuntimeId = RuntimeBlockMapping::toStaticRuntimeId($block->getId(), $block->getDamage());
			}
		}
		$this->putVarInt($blockRuntimeId);

		$nbt = null;
		if($item->hasCompoundTag()){
			$nbt = clone $item->getNamedTag();
		}
		if($item instanceof Durable and $coreData > 0){
			if($nbt !== null){
				if(($existing = $nbt->getTag(self::DAMAGE_TAG)) !== null){
					$nbt->removeTag(self::DAMAGE_TAG);
					$existing->setName(self::DAMAGE_TAG_CONFLICT_RESOLUTION);
					$nbt->setTag($existing);
				}
			}else{
				$nbt = new CompoundTag();
			}
			$nbt->setInt(self::DAMAGE_TAG, $coreData);
		}elseif($isBlockItem && $coreData !== 0){
			//TODO HACK: This foul-smelling code ensures that we can correctly deserialize an item when the
			//client sends it back to us, because as of 1.16.220, blockitems quietly discard their metadata
			//client-side. Aside from being very annoying, this also breaks various server-side behaviours.
			if($nbt === null){
				$nbt = new CompoundTag();
			}
			$nbt->setInt(self::PM_META_TAG, $coreData);
		}

		$this->putString(
		(static function() use ($nbt, $netId) : string{
			$extraData = new NetworkBinaryStream();

			if($nbt !== null){
				$extraData->putLShort(0xffff);
				$extraData->putByte(1); //TODO: NBT data version (?)
				$extraData->put((new LittleEndianNBTStream())->write($nbt));
			}else{
				$extraData->putLShort(0);
			}

			$extraData->putLInt(0); //CanPlaceOn entry count (TODO)
			$extraData->putLInt(0); //CanDestroy entry count (TODO)

			if($netId === ItemTypeDictionary::getInstance()->fromStringId("minecraft:shield")){
				$extraData->putLLong(0); //"blocking tick" (ffs mojang)
			}
			return $extraData->getBuffer();
		})());
	}

	public function getRecipeIngredient() : Item{
		$netId = $this->getVarInt();
		if($netId === 0){
			return ItemFactory::get(ItemIds::AIR, 0, 0);
		}
		$netData = $this->getVarInt();
		[$id, $meta] = ItemTranslator::getInstance()->fromNetworkIdWithWildcardHandling($netId, $netData);
		$count = $this->getVarInt();
		return ItemFactory::get($id, $meta, $count);
	}

	public function putRecipeIngredient(Item $item) : void{
		if($item->isNull()){
			$this->putVarInt(0);
		}else{
			if($item->hasAnyDamageValue()){
				[$netId, ] = ItemTranslator::getInstance()->toNetworkId($item->getId(), 0);
				$netData = 0x7fff;
			}else{
				[$netId, $netData] = ItemTranslator::getInstance()->toNetworkId($item->getId(), $item->getDamage());
			}
			$this->putVarInt($netId);
			$this->putVarInt($netData);
			$this->putVarInt($item->getCount());
		}
	}

	/**
	 * Decodes entity metadata from the stream.
	 *
	 * @param bool $types Whether to include metadata types along with values in the returned array
	 *
	 * @return mixed[]|mixed[][]
	 * @phpstan-return array<int, mixed>|array<int, array{0: int, 1: mixed}>
	 */
	public function getEntityMetadata(bool $types = true) : array{
		$count = $this->getUnsignedVarInt();
		$data = [];
		for($i = 0; $i < $count; ++$i){
			$key = $this->getUnsignedVarInt();
			$type = $this->getUnsignedVarInt();
			$value = null;
			switch($type){
				case Entity::DATA_TYPE_BYTE:
					$value = (\ord($this->get(1)));
					break;
				case Entity::DATA_TYPE_SHORT:
					$value = ((\unpack("v", $this->get(2))[1] << 48 >> 48));
					break;
				case Entity::DATA_TYPE_INT:
					$value = $this->getVarInt();
					break;
				case Entity::DATA_TYPE_FLOAT:
					$value = ((\unpack("g", $this->get(4))[1]));
					break;
				case Entity::DATA_TYPE_STRING:
					$value = $this->getString();
					break;
				case Entity::DATA_TYPE_COMPOUND_TAG:
					$value = (new NetworkLittleEndianNBTStream())->read($this->buffer, false, $this->offset, 512);
					break;
				case Entity::DATA_TYPE_POS:
					$value = new Vector3();
					$this->getSignedBlockPosition($value->x, $value->y, $value->z);
					break;
				case Entity::DATA_TYPE_LONG:
					$value = $this->getVarLong();
					break;
				case Entity::DATA_TYPE_VECTOR3F:
					$value = $this->getVector3();
					break;
				default:
					throw new \UnexpectedValueException("Invalid data type " . $type);
			}
			if($types){
				$data[$key] = [$type, $value];
			}else{
				$data[$key] = $value;
			}
		}

		return $data;
	}

	/**
	 * Writes entity metadata to the packet buffer.
	 *
	 * @param mixed[][] $metadata
	 * @phpstan-param array<int, array{0: int, 1: mixed}> $metadata
	 */
	public function putEntityMetadata(array $metadata) : void{
		$this->putUnsignedVarInt(count($metadata));
		foreach($metadata as $key => $d){
			$this->putUnsignedVarInt($key); //data key
			$this->putUnsignedVarInt($d[0]); //data type
			switch($d[0]){
				case Entity::DATA_TYPE_BYTE:
					($this->buffer .= \chr($d[1]));
					break;
				case Entity::DATA_TYPE_SHORT:
					($this->buffer .= (\pack("v", $d[1]))); //SIGNED short!
					break;
				case Entity::DATA_TYPE_INT:
					$this->putVarInt($d[1]);
					break;
				case Entity::DATA_TYPE_FLOAT:
					($this->buffer .= (\pack("g", $d[1])));
					break;
				case Entity::DATA_TYPE_STRING:
					$this->putString($d[1]);
					break;
				case Entity::DATA_TYPE_COMPOUND_TAG:
					($this->buffer .= (new NetworkLittleEndianNBTStream())->write($d[1]));
					break;
				case Entity::DATA_TYPE_POS:
					$v = $d[1];
					if($v !== null){
						$this->putSignedBlockPosition($v->x, $v->y, $v->z);
					}else{
						$this->putSignedBlockPosition(0, 0, 0);
					}
					break;
				case Entity::DATA_TYPE_LONG:
					$this->putVarLong($d[1]);
					break;
				case Entity::DATA_TYPE_VECTOR3F:
					$this->putVector3Nullable($d[1]);
					break;
				default:
					throw new \UnexpectedValueException("Invalid data type " . $d[0]);
			}
		}
	}

	/**
	 * Reads a list of Attributes from the stream.
	 * @return Attribute[]
	 *
	 * @throws \UnexpectedValueException if reading an attribute with an unrecognized name
	 */
	public function getAttributeList() : array{
		$list = [];
		$count = $this->getUnsignedVarInt();

		for($i = 0; $i < $count; ++$i){
			$min = ((\unpack("g", $this->get(4))[1]));
			$max = ((\unpack("g", $this->get(4))[1]));
			$current = ((\unpack("g", $this->get(4))[1]));
			$default = ((\unpack("g", $this->get(4))[1]));
			$name = $this->getString();

			$attr = Attribute::getAttributeByName($name);
			if($attr !== null){
				$attr->setMinValue($min);
				$attr->setMaxValue($max);
				$attr->setValue($current);
				$attr->setDefaultValue($default);

				$list[] = $attr;
			}else{
				throw new \UnexpectedValueException("Unknown attribute type \"$name\"");
			}
		}

		return $list;
	}

	/**
	 * Writes a list of Attributes to the packet buffer using the standard format.
	 */
	public function putAttributeList(Attribute ...$attributes) : void{
		$this->putUnsignedVarInt(count($attributes));
		foreach($attributes as $attribute){
			($this->buffer .= (\pack("g", $attribute->getMinValue())));
			($this->buffer .= (\pack("g", $attribute->getMaxValue())));
			($this->buffer .= (\pack("g", $attribute->getValue())));
			($this->buffer .= (\pack("g", $attribute->getDefaultValue())));
			$this->putString($attribute->getName());
		}
	}

	/**
	 * Reads and returns an EntityUniqueID
	 */
	final public function getEntityUniqueId() : int{
		return $this->getVarLong();
	}

	/**
	 * Writes an EntityUniqueID
	 */
	public function putEntityUniqueId(int $eid) : void{
		$this->putVarLong($eid);
	}

	/**
	 * Reads and returns an EntityRuntimeID
	 */
	final public function getEntityRuntimeId() : int{
		return $this->getUnsignedVarLong();
	}

	/**
	 * Writes an EntityRuntimeID
	 */
	public function putEntityRuntimeId(int $eid) : void{
		$this->putUnsignedVarLong($eid);
	}

	/**
	 * Reads an block position with unsigned Y coordinate.
	 *
	 * @param int $x reference parameter
	 * @param int $y reference parameter
	 * @param int $z reference parameter
	 */
	public function getBlockPosition(&$x, &$y, &$z) : void{
		$x = $this->getVarInt();
		$y = $this->getUnsignedVarInt();
		$z = $this->getVarInt();
	}

	/**
	 * Writes a block position with unsigned Y coordinate.
	 */
	public function putBlockPosition(int $x, int $y, int $z) : void{
		$this->putVarInt($x);
		$this->putUnsignedVarInt($y);
		$this->putVarInt($z);
	}

	/**
	 * Reads a block position with a signed Y coordinate.
	 *
	 * @param int $x reference parameter
	 * @param int $y reference parameter
	 * @param int $z reference parameter
	 */
	public function getSignedBlockPosition(&$x, &$y, &$z) : void{
		$x = $this->getVarInt();
		$y = $this->getVarInt();
		$z = $this->getVarInt();
	}

	/**
	 * Writes a block position with a signed Y coordinate.
	 */
	public function putSignedBlockPosition(int $x, int $y, int $z) : void{
		$this->putVarInt($x);
		$this->putVarInt($y);
		$this->putVarInt($z);
	}

	/**
	 * Reads a floating-point Vector3 object with coordinates rounded to 4 decimal places.
	 */
	public function getVector3() : Vector3{
		$x = ((\unpack("g", $this->get(4))[1]));
		$y = ((\unpack("g", $this->get(4))[1]));
		$z = ((\unpack("g", $this->get(4))[1]));
		return new Vector3($x, $y, $z);
	}

	/**
	 * Writes a floating-point Vector3 object, or 3x zero if null is given.
	 *
	 * Note: ONLY use this where it is reasonable to allow not specifying the vector.
	 * For all other purposes, use the non-nullable version.
	 *
	 * @see NetworkBinaryStream::putVector3()
	 */
	public function putVector3Nullable(?Vector3 $vector) : void{
		if($vector !== null){
			$this->putVector3($vector);
		}else{
			($this->buffer .= (\pack("g", 0.0)));
			($this->buffer .= (\pack("g", 0.0)));
			($this->buffer .= (\pack("g", 0.0)));
		}
	}

	/**
	 * Writes a floating-point Vector3 object
	 */
	public function putVector3(Vector3 $vector) : void{
		($this->buffer .= (\pack("g", $vector->x)));
		($this->buffer .= (\pack("g", $vector->y)));
		($this->buffer .= (\pack("g", $vector->z)));
	}

	public function getByteRotation() : float{
		return ((\ord($this->get(1))) * (360 / 256));
	}

	public function putByteRotation(float $rotation) : void{
		($this->buffer .= \chr((int) ($rotation / (360 / 256))));
	}

	/**
	 * Reads gamerules
	 * TODO: implement this properly
	 *
	 * @return mixed[][], members are in the structure [name => [type, value, isPlayerModifiable]]
	 * @phpstan-return array<string, array{0: int, 1: bool|int|float, 2: bool}>
	 */
	public function getGameRules() : array{
		$count = $this->getUnsignedVarInt();
		$rules = [];
		for($i = 0; $i < $count; ++$i){
			$name = $this->getString();
			$isPlayerModifiable = (($this->get(1) !== "\x00"));
			$type = $this->getUnsignedVarInt();
			$value = null;
			switch($type){
				case GameRuleType::BOOL:
					$value = (($this->get(1) !== "\x00"));
					break;
				case GameRuleType::INT:
					$value = $this->getUnsignedVarInt();
					break;
				case GameRuleType::FLOAT:
					$value = ((\unpack("g", $this->get(4))[1]));
					break;
			}

			$rules[$name] = [$type, $value, $isPlayerModifiable];
		}

		return $rules;
	}

	/**
	 * Writes a gamerule array, members should be in the structure [name => [type, value, isPlayerModifiable]]
	 * TODO: implement this properly
	 *
	 * @param mixed[][] $rules
	 * @phpstan-param array<string, array{0: int, 1: bool|int|float, 2: bool}> $rules
	 */
	public function putGameRules(array $rules) : void{
		$this->putUnsignedVarInt(count($rules));
		foreach($rules as $name => $rule){
			$this->putString($name);
			($this->buffer .= ($rule[2] ? "\x01" : "\x00"));
			$this->putUnsignedVarInt($rule[0]);
			switch($rule[0]){
				case GameRuleType::BOOL:
					($this->buffer .= ($rule[1] ? "\x01" : "\x00"));
					break;
				case GameRuleType::INT:
					$this->putUnsignedVarInt($rule[1]);
					break;
				case GameRuleType::FLOAT:
					($this->buffer .= (\pack("g", $rule[1])));
					break;
			}
		}
	}

	protected function getEntityLink() : EntityLink{
		$fromEntityUniqueId = $this->getEntityUniqueId();
		$toEntityUniqueId = $this->getEntityUniqueId();
		$type = (\ord($this->get(1)));
		$immediate = (($this->get(1) !== "\x00"));
		$causedByRider = (($this->get(1) !== "\x00"));
		return new EntityLink($fromEntityUniqueId, $toEntityUniqueId, $type, $immediate, $causedByRider);
	}

	protected function putEntityLink(EntityLink $link) : void{
		$this->putEntityUniqueId($link->fromEntityUniqueId);
		$this->putEntityUniqueId($link->toEntityUniqueId);
		($this->buffer .= \chr($link->type));
		($this->buffer .= ($link->immediate ? "\x01" : "\x00"));
		($this->buffer .= ($link->causedByRider ? "\x01" : "\x00"));
	}

	protected function getCommandOriginData() : CommandOriginData{
		$result = new CommandOriginData();

		$result->type = $this->getUnsignedVarInt();
		$result->uuid = $this->getUUID();
		$result->requestId = $this->getString();

		if($result->type === CommandOriginData::ORIGIN_DEV_CONSOLE or $result->type === CommandOriginData::ORIGIN_TEST){
			$result->playerEntityUniqueId = $this->getVarLong();
		}

		return $result;
	}

	protected function putCommandOriginData(CommandOriginData $data) : void{
		$this->putUnsignedVarInt($data->type);
		$this->putUUID($data->uuid);
		$this->putString($data->requestId);

		if($data->type === CommandOriginData::ORIGIN_DEV_CONSOLE or $data->type === CommandOriginData::ORIGIN_TEST){
			$this->putVarLong($data->playerEntityUniqueId);
		}
	}

	protected function getStructureSettings() : StructureSettings{
		$result = new StructureSettings();

		$result->paletteName = $this->getString();

		$result->ignoreEntities = (($this->get(1) !== "\x00"));
		$result->ignoreBlocks = (($this->get(1) !== "\x00"));

		$this->getBlockPosition($result->structureSizeX, $result->structureSizeY, $result->structureSizeZ);
		$this->getBlockPosition($result->structureOffsetX, $result->structureOffsetY, $result->structureOffsetZ);

		$result->lastTouchedByPlayerID = $this->getEntityUniqueId();
		$result->rotation = (\ord($this->get(1)));
		$result->mirror = (\ord($this->get(1)));
		$result->animationMode = (\ord($this->get(1)));
		$result->animationSeconds = ((\unpack("g", $this->get(4))[1]));
		$result->integrityValue = ((\unpack("g", $this->get(4))[1]));
		$result->integritySeed = ((\unpack("V", $this->get(4))[1] << 32 >> 32));
		$result->pivot = $this->getVector3();

		return $result;
	}

	protected function putStructureSettings(StructureSettings $structureSettings) : void{
		$this->putString($structureSettings->paletteName);

		($this->buffer .= ($structureSettings->ignoreEntities ? "\x01" : "\x00"));
		($this->buffer .= ($structureSettings->ignoreBlocks ? "\x01" : "\x00"));

		$this->putBlockPosition($structureSettings->structureSizeX, $structureSettings->structureSizeY, $structureSettings->structureSizeZ);
		$this->putBlockPosition($structureSettings->structureOffsetX, $structureSettings->structureOffsetY, $structureSettings->structureOffsetZ);

		$this->putEntityUniqueId($structureSettings->lastTouchedByPlayerID);
		($this->buffer .= \chr($structureSettings->rotation));
		($this->buffer .= \chr($structureSettings->mirror));
		($this->buffer .= \chr($structureSettings->animationMode));
		($this->buffer .= (\pack("g", $structureSettings->animationSeconds)));
		($this->buffer .= (\pack("g", $structureSettings->integrityValue)));
		($this->buffer .= (\pack("V", $structureSettings->integritySeed)));
		$this->putVector3($structureSettings->pivot);
	}

	protected function getStructureEditorData() : StructureEditorData{
		$result = new StructureEditorData();

		$result->structureName = $this->getString();
		$result->structureDataField = $this->getString();

		$result->includePlayers = (($this->get(1) !== "\x00"));
		$result->showBoundingBox = (($this->get(1) !== "\x00"));

		$result->structureBlockType = $this->getVarInt();
		$result->structureSettings = $this->getStructureSettings();
		$result->structureRedstoneSaveMove = $this->getVarInt();

		return $result;
	}

	protected function putStructureEditorData(StructureEditorData $structureEditorData) : void{
		$this->putString($structureEditorData->structureName);
		$this->putString($structureEditorData->structureDataField);

		($this->buffer .= ($structureEditorData->includePlayers ? "\x01" : "\x00"));
		($this->buffer .= ($structureEditorData->showBoundingBox ? "\x01" : "\x00"));

		$this->putVarInt($structureEditorData->structureBlockType);
		$this->putStructureSettings($structureEditorData->structureSettings);
		$this->putVarInt($structureEditorData->structureRedstoneSaveMove);
	}

	public function getNbtRoot() : NamedTag{
		$offset = $this->getOffset();
		try{
			$result = (new NetworkLittleEndianNBTStream())->read($this->getBuffer(), false, $offset, 512);
			assert($result instanceof NamedTag, "doMultiple is false so we should definitely have a NamedTag here");
			return $result;
		}finally{
			$this->setOffset($offset);
		}
	}

	public function getNbtCompoundRoot() : CompoundTag{
		$root = $this->getNbtRoot();
		if(!($root instanceof CompoundTag)){
			throw new \UnexpectedValueException("Expected TAG_Compound root");
		}
		return $root;
	}

	public function readGenericTypeNetworkId() : int{
		return $this->getVarInt();
	}

	public function writeGenericTypeNetworkId(int $id) : void{
		$this->putVarInt($id);
	}
}
