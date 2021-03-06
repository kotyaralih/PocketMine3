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

namespace pocketmine\network\mcpe\protocol;

use pocketmine\utils\Binary;

use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\types\CommandOriginData;
use pocketmine\network\mcpe\protocol\types\CommandOutputMessage;
use function count;

class CommandOutputPacket extends DataPacket{
	public const NETWORK_ID = ProtocolInfo::COMMAND_OUTPUT_PACKET;

	public const TYPE_LAST = 1;
	public const TYPE_SILENT = 2;
	public const TYPE_ALL = 3;
	public const TYPE_DATA_SET = 4;

	/** @var CommandOriginData */
	public $originData;
	/** @var int */
	public $outputType;
	/** @var int */
	public $successCount;
	/** @var CommandOutputMessage[] */
	public $messages = [];
	/** @var string */
	public $unknownString;

	protected function decodePayload(){
		$this->originData = $this->getCommandOriginData();
		$this->outputType = (\ord($this->get(1)));
		$this->successCount = $this->getUnsignedVarInt();

		for($i = 0, $size = $this->getUnsignedVarInt(); $i < $size; ++$i){
			$this->messages[] = $this->getCommandMessage();
		}

		if($this->outputType === self::TYPE_DATA_SET){
			$this->unknownString = $this->getString();
		}
	}

	protected function getCommandMessage() : CommandOutputMessage{
		$message = new CommandOutputMessage();

		$message->isInternal = (($this->get(1) !== "\x00"));
		$message->messageId = $this->getString();

		for($i = 0, $size = $this->getUnsignedVarInt(); $i < $size; ++$i){
			$message->parameters[] = $this->getString();
		}

		return $message;
	}

	protected function encodePayload(){
		$this->putCommandOriginData($this->originData);
		($this->buffer .= \chr($this->outputType));
		$this->putUnsignedVarInt($this->successCount);

		$this->putUnsignedVarInt(count($this->messages));
		foreach($this->messages as $message){
			$this->putCommandMessage($message);
		}

		if($this->outputType === self::TYPE_DATA_SET){
			$this->putString($this->unknownString);
		}
	}

	/**
	 * @return void
	 */
	protected function putCommandMessage(CommandOutputMessage $message){
		($this->buffer .= ($message->isInternal ? "\x01" : "\x00"));
		$this->putString($message->messageId);

		$this->putUnsignedVarInt(count($message->parameters));
		foreach($message->parameters as $parameter){
			$this->putString($parameter);
		}
	}

	public function handle(NetworkSession $session) : bool{
		return $session->handleCommandOutput($this);
	}
}
