<?php

namespace OCA\GroupFolders\Trash;

use OCA\Files_Trashbin\Trash\ITrashBackend;
use OCP\Files\FileInfo;
use OCP\IUser;

class FakeGroupTrashDir extends GroupTrashItem {
	private string $name;

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
		parent::__construct($backend, $originalLocation, $deletedTime, $trashPath, $fileInfo, $user, $mountPoint);
		$this->name = $mountPoint;
		$this->folderId = $folderId;
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function getFolderId(): int
	{
		return $this->folderId;
	}
}
