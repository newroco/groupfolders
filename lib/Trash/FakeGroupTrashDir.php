<?php

namespace OCA\GroupFolders\Trash;

use OCA\Files_Trashbin\Trash\ITrashBackend;
use OCP\Files\FileInfo;
use OCP\IUser;

class FakeGroupTrashDir extends GroupTrashItem {
	private string $name;

	/** @var FileInfo */
	private $fileInfo;

    const TYPE = "FAKEDIRECTORY";

	public function __construct(
		ITrashBackend $backend,
		string $originalLocation,
		int $deletedTime,
		string $trashPath,
		FileInfo $fileInfo,
		IUser $user,
		string $mountPoint,
		int $folderId
	) {
		parent::__construct($backend, $originalLocation, $deletedTime, $trashPath, $fileInfo, $user, $originalLocation);
		$this->name = $mountPoint;
		$this->folderId = $folderId;
		$this->fileInfo = $fileInfo;
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function getFolderId(): int
	{
		return $this->folderId;
	}

	public function getFileId(): int
	{
		return $this->fileInfo->getId();
	}

	public function isFakeDir()
	{
		return true;
	}
}
