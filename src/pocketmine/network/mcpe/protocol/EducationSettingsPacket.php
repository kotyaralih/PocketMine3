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
use pocketmine\network\mcpe\protocol\types\EducationSettingsAgentCapabilities;
use pocketmine\network\mcpe\protocol\types\EducationSettingsExternalLinkSettings;

class EducationSettingsPacket extends DataPacket{
	public const NETWORK_ID = ProtocolInfo::EDUCATION_SETTINGS_PACKET;

	/** @var string */
	private $codeBuilderDefaultUri;
	/** @var string */
	private $codeBuilderTitle;
	/** @var bool */
	private $canResizeCodeBuilder;
	/** @var bool */
	private $disableLegacyTitleBar;
	/** @var string */
	private $postProcessFilter;
	/** @var string */
	private $screenshotBorderResourcePath;
	/** @var EducationSettingsAgentCapabilities|null */
	private $agentCapabilities;
	/** @var string|null */
	private $codeBuilderOverrideUri;
	/** @var bool */
	private $hasQuiz;
	/** @var EducationSettingsExternalLinkSettings|null */
	private $linkSettings;

	public static function create(
		string $codeBuilderDefaultUri,
		string $codeBuilderTitle,
		bool $canResizeCodeBuilder,
		bool $disableLegacyTitleBar,
		string $postProcessFilter,
		string $screenshotBorderResourcePath,
		?EducationSettingsAgentCapabilities $agentCapabilities,
		?string $codeBuilderOverrideUri,
		bool $hasQuiz,
		?EducationSettingsExternalLinkSettings $linkSettings
	) : self{
		$result = new self;
		$result->codeBuilderDefaultUri = $codeBuilderDefaultUri;
		$result->codeBuilderTitle = $codeBuilderTitle;
		$result->canResizeCodeBuilder = $canResizeCodeBuilder;
		$result->disableLegacyTitleBar = $disableLegacyTitleBar;
		$result->postProcessFilter = $postProcessFilter;
		$result->screenshotBorderResourcePath = $screenshotBorderResourcePath;
		$result->agentCapabilities = $agentCapabilities;
		$result->codeBuilderOverrideUri = $codeBuilderOverrideUri;
		$result->hasQuiz = $hasQuiz;
		$result->linkSettings = $linkSettings;
		return $result;
	}

	public function getCodeBuilderDefaultUri() : string{
		return $this->codeBuilderDefaultUri;
	}

	public function getCodeBuilderTitle() : string{
		return $this->codeBuilderTitle;
	}

	public function canResizeCodeBuilder() : bool{
		return $this->canResizeCodeBuilder;
	}

	public function disableLegacyTitleBar() : bool{ return $this->disableLegacyTitleBar; }

	public function getPostProcessFilter() : string{ return $this->postProcessFilter; }

	public function getScreenshotBorderResourcePath() : string{ return $this->screenshotBorderResourcePath; }

	public function getAgentCapabilities() : ?EducationSettingsAgentCapabilities{ return $this->agentCapabilities; }

	public function getCodeBuilderOverrideUri() : ?string{
		return $this->codeBuilderOverrideUri;
	}

	public function getHasQuiz() : bool{
		return $this->hasQuiz;
	}

	public function getLinkSettings() : ?EducationSettingsExternalLinkSettings{ return $this->linkSettings; }

	protected function decodePayload() : void{
		$this->codeBuilderDefaultUri = $this->getString();
		$this->codeBuilderTitle = $this->getString();
		$this->canResizeCodeBuilder = (($this->get(1) !== "\x00"));
		$this->disableLegacyTitleBar = (($this->get(1) !== "\x00"));
		$this->postProcessFilter = $this->getString();
		$this->screenshotBorderResourcePath = $this->getString();
		$this->agentCapabilities = (($this->get(1) !== "\x00")) ? EducationSettingsAgentCapabilities::read($this) : null;
		if((($this->get(1) !== "\x00"))){
			$this->codeBuilderOverrideUri = $this->getString();
		}else{
			$this->codeBuilderOverrideUri = null;
		}
		$this->hasQuiz = (($this->get(1) !== "\x00"));
		$this->linkSettings = (($this->get(1) !== "\x00")) ? EducationSettingsExternalLinkSettings::read($this) : null;
	}

	protected function encodePayload() : void{
		$this->putString($this->codeBuilderDefaultUri);
		$this->putString($this->codeBuilderTitle);
		($this->buffer .= ($this->canResizeCodeBuilder ? "\x01" : "\x00"));
		($this->buffer .= ($this->disableLegacyTitleBar ? "\x01" : "\x00"));
		$this->putString($this->postProcessFilter);
		$this->putString($this->screenshotBorderResourcePath);
		$agentCapabilities = $this->agentCapabilities;
		if($agentCapabilities !== null){
			($this->buffer .= (true ? "\x01" : "\x00"));
			$agentCapabilities->write($this);
		}else{
			($this->buffer .= (false ? "\x01" : "\x00"));
		}
		($this->buffer .= ($this->codeBuilderOverrideUri !== null ? "\x01" : "\x00"));
		if($this->codeBuilderOverrideUri !== null){
			$this->putString($this->codeBuilderOverrideUri);
		}
		($this->buffer .= ($this->hasQuiz ? "\x01" : "\x00"));
		$linkSettings = $this->linkSettings;
		if($linkSettings !== null){
			($this->buffer .= (true ? "\x01" : "\x00"));
			$linkSettings->write($this);
		}else{
			($this->buffer .= (false ? "\x01" : "\x00"));
		}
	}

	public function handle(NetworkSession $handler) : bool{
		return $handler->handleEducationSettings($this);
	}
}
