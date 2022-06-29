<?php
/**
 * @copyright Copyright (c) 2018 Robin Appelman <robin@icewind.nl>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\GroupFolders\Trash;

use OC\Files\Storage\Wrapper\Jail;
use OCA\Files_Trashbin\Expiration;
use OCA\Files_Trashbin\Trash\ITrashBackend;
use OCA\Files_Trashbin\Trash\ITrashItem;
use OCA\GroupFolders\ACL\ACLManagerFactory;
use OCA\GroupFolders\Folder\FolderManager;
use OCA\GroupFolders\Mount\GroupFolderStorage;
use OCA\GroupFolders\Mount\MountProvider;
use OCA\GroupFolders\Versions\VersionsBackend;
use OCP\Constants;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\Files\Storage\IStorage;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OC\Files\View;

class TrashBackend implements ITrashBackend {
	private FolderManager $folderManager;
	private TrashManager $trashManager;
	private Folder $appFolder;
	private MountProvider $mountProvider;
	private ACLManagerFactory $aclManagerFactory;
	/** @var ?VersionsBackend */
	private $versionsBackend = null;
	private IRootFolder $rootFolder;
	private string $location = '';

	public function __construct(
		FolderManager $folderManager,
		TrashManager $trashManager,
		Folder $appFolder,
		MountProvider $mountProvider,
		ACLManagerFactory $aclManagerFactory,
		IRootFolder $rootFolder
	) {
		$this->folderManager = $folderManager;
		$this->trashManager = $trashManager;
		$this->appFolder = $appFolder;
		$this->mountProvider = $mountProvider;
		$this->aclManagerFactory = $aclManagerFactory;
		$this->rootFolder = $rootFolder;
	}

	public function setVersionsBackend(VersionsBackend $versionsBackend): void {
		$this->versionsBackend = $versionsBackend;
	}

	// /**
	//  * @return list<ITrashItem>
	//  */
	// public function listTrashRoot(IUser $user): array {
	// 	$folders = $this->folderManager->getFoldersForUser($user);
	// 	return $this->getTrashForFolders($user, $folders);
	// }

	/**
	 * @return list<ITrashItem>
	 */
	public function listTrashRoot(IUser $user): array {
		$folderIds = $this->trashManager->groupTrashFolderIds();
		$items = [];

		foreach($folderIds as $id) {
			$folder = $this->folderManager->getFolder($id, 0);
			if(empty($folder)) {
				continue;
			}
			$items[] = new FakeGroupTrashDir(
				$this,
				$folder['mount_point'],
				$this->trashManager->getTimestampForFolder($id),
				'/' . $folder['folder_id'],
				$this->getTrashFolder($id),
				$user,
				$folder['mount_point'],
				$id
			);
		}

		return $items;
	}

	private function setLocation($currentDir)
	{
		$this->location .= '/' . $currentDir;
	}

	private function getLocation()
	{
		return trim($this->location, '/');
	}

	private function getFakeFolderItemsFromDB($trashItem, $user)
	{
		$view = new View('/__groupfolders/trash/');
		$folders = $items = [];
		$slashLimit = 0;
		$folderId = $trashItem->getFolderId();
		$rootFolder = $this->getTrashFolder($folderId);
		$folderName = $trashItem->getName();			
		if($this->folderManager->getFolder($folderId, 0)['mount_point'] !== $folderName) {
			$this->setLocation($folderName);
		}
		$location = $this->getLocation();

		if($location === '') {
			$slashLimit = 1;
		} else if (substr_count($location, '/') > 0) {
			$slashLimit = substr_count($location, '/') + 2;
		} else {
			$slashLimit = 2;
		}

		$entries = $this->trashManager->getItemsForFolder($folderId, $location, $slashLimit);
		$fakeFoldersNeeded = $this->trashManager->getNeededFolders($folderId, $location, $slashLimit);
		if(count($fakeFoldersNeeded) > 0) {
			$folders = array_map(function($folder) use ($folderId, $rootFolder, $user, $location) {
				return new FakeGroupTrashDir(
					$this,
					'',
					$this->trashManager->getTimestampForFolder($folderId, $location == '' ? $folder : $location),
					$location,
					$rootFolder,
					$user,
					$folder,
					$folderId
				);
			}, $fakeFoldersNeeded);
		}

		$items = array_map(function($entry) use ($folderId, $view, $user, $folderName) {
			$name = $entry['name'];
			$deletedAt = $entry['deleted_time'];
			$originalLocation = $entry['original_location'];
			$fileInfo = $view->getFileInfo($folderId . '/' . $name . '.d' . $deletedAt);
			$fileInfo['name'] = $name;

			return new GroupTrashItem(
				$this,
				$originalLocation,
				$deletedAt,
				$entry['folder_id'] . '/' . $name,
				$fileInfo,
				$user,
				$folderName
			);
		}, $entries);

		$items = array_merge($items, $folders);

		usort($items, function (ITrashItem $a, ITrashItem $b) {
				return $b->getDeletedTime() - $a->getDeletedTime();
		});

		return $items;
	}

	/**
	 * @return list<ITrashItem>
	 */
	public function listTrashFolder(ITrashItem $trashItem): array {
		$user = $trashItem->getUser();

		if($trashItem instanceof FakeGroupTrashDir) {
			return $this->getFakeFolderItemsFromDB($trashItem, $user);
		} else if (!$trashItem instanceof GroupTrashItem) {
			return [];
		}
		// a subfolderfolder was deleted from the groupfolder,
		// display it's content using DAV
		$folder = $this->getNodeForTrashItem($user, $trashItem);
		if (!$folder instanceof Folder) {
			return [];
		}
		$content = $folder->getDirectoryListing();
		return  array_map(function (Node $node) use ($trashItem, $user) {
			return new GroupTrashItem(
				$this,
				$trashItem->getOriginalLocation() . '/' . $node->getName(),
				$trashItem->getDeletedTime(),
				$trashItem->getTrashPath() . '/' . $node->getName(),
				$node,
				$user,
				$trashItem->getGroupFolderMountPoint()
			);
		}, $content);
	}

	private function checkRestorePermissions(Node $node, IUser $user, int $folderId, string $originalLocation)
	{
		if ($node === null) {
			throw new NotFoundException();
		}
		if (!$this->userHasAccessToPath($user, $folderId . '/' . $originalLocation, Constants::PERMISSION_UPDATE)) {
			throw new NotPermittedException();
		}
		$folderPermissions = $this->folderManager->getFolderPermissionsForUser($user, (int)$folderId);
		if (($folderPermissions & Constants::PERMISSION_UPDATE) !== Constants::PERMISSION_UPDATE) {
			throw new NotPermittedException();
		}
	}

	private function restoreAllItemsFromFakeFolder(FakeGroupTrashDir $folder): void
	{
		if($folder->getTrashPath() === '/') {
			$location = '';
		} else if ($folder->getTrashPath() !== '') {
			$location = $folder->getTrashPath() . '/' . $folder->getName();
		} else {
			$location = $folder->getName();
		}
		$user = $folder->getUser();
		$folderId = $folder->getFolderId();
		$wantedFields = ['file_id', 'name', 'original_location', 'deleted_time'];
		$items = $this->trashManager->getItemsForFolder($folderId, $location, 0, $wantedFields);
		foreach($items as $item) {
			$node = $this->getTrashNodeById($user, $item['file_id']);
			$originalLocation = $item['original_location'];
			$this->checkRestorePermissions($node, $user, $folderId, $item['original_location']);

			$this->restore($node, $folderId, $item, $originalLocation);
			$this->trashManager->removeItem((int)$folderId, $item['name'], $item['deleted_time']);
		}
	}

	/**
	 * @return void
	 * @throw NotPermittedException
	 */
	public function restoreItem(ITrashItem $item) {
		if (!($item instanceof GroupTrashItem)) {
			throw new \LogicException('Trying to restore normal trash item in group folder trash backend');
		}
		$user = $item->getUser();
		if($item instanceof FakeGroupTrashDir && $item->isFakeDir()) {
			// if item is a fakedir, restore all items within it and stop execution
			return $this->restoreAllItemsFromFakeFolder($item);
		}
		[$folderId,] = explode('/', $item->getTrashPath());
		$node = $this->getNodeForTrashItem($user, $item);
		$this->checkRestorePermissions($node, $user, $folderId, $item->getOriginalLocation());

		$this->restore($node, $folderId, $item);
		$this->trashManager->removeItem((int)$folderId, $item->getName(), $item->getDeletedTime());
	}

	private function restore($node, $folderId, $item, $originalLocation = null)
	{
		$trashStorage = $node->getStorage();
		/** @var Folder $targetFolder */
		$targetFolder = $this->mountProvider->getFolder((int)$folderId);
		$originalLocation = $originalLocation ?? $item->getOriginalLocation();
		$parent = dirname($originalLocation);
		if ($parent === '.') {
			$parent = '';
		}
		if ($parent !== '' && !$targetFolder->nodeExists($parent)) {
			$originalLocation = basename($originalLocation);
		}

		if ($targetFolder->nodeExists($originalLocation)) {
			$info = pathinfo($originalLocation);
			$i = 1;

			$gen = function ($info, int $i): string {
				$target = $info['dirname'];
				if ($target === '.') {
					$target = '';
				}

				$target .= $info['filename'];
				$target .= ' (' . $i . ')';

				if (isset($info['extension'])) {
					$target .= $info['extension'];
				}

				return $target;
			};

			do {
				$originalLocation = $gen($info, $i);
				$i++;
			} while ($targetFolder->nodeExists($originalLocation));
		}

		$targetLocation = $targetFolder->getInternalPath() . '/' . $originalLocation;
		$targetFolder->getStorage()->moveFromStorage($trashStorage, $node->getInternalPath(), $targetLocation);
		$targetFolder->getStorage()->getUpdater()->renameFromStorage($trashStorage, $node->getInternalPath(), $targetLocation);
	}

	private function removeAllItemsFromFakeFolder(FakeGroupTrashDir $folder): void 
	{
		if($folder->getTrashPath() === '/') {
			$location = '';
		} else if ($folder->getTrashPath() !== '') {
			$location = $folder->getTrashPath() . '/' . $folder->getName();
		} else {
			$location = $folder->getName();
		}
		$user = $folder->getUser();
		$folderId = $folder->getFolderId();
		$wantedFields = ['file_id', 'name', 'original_location', 'deleted_time'];
		$itemIds = $this->trashManager->getItemsForFolder($folderId, $location, 0, $wantedFields);

		foreach($itemIds as $item) {
			$node = $this->getTrashNodeById($user, $item['file_id']);
			$this->checkDeletePermissions($node, $user, $folderId, $item['original_location']);

			$node->getStorage()->getCache()->remove($node->getInternalPath());
			$this->trashManager->removeItem((int)$folderId, $item['name'], $item['deleted_time']);
		}
	}

	private function checkDeletePermissions(Node $node, IUser $user, int $folderId, string $originalLocation) {
		if ($node === null) {
			throw new NotFoundException();
		}
		if ($node->getStorage()->unlink($node->getInternalPath()) === false) {
			throw new \Exception('Failed to remove item from trashbin');
		}
		if (!$this->userHasAccessToPath($user, $folderId . '/' . $originalLocation, Constants::PERMISSION_DELETE)) {
			throw new NotPermittedException();
		}
	}

	/**
	 * @return void
	 * @throw \LogicException
	 * @throw \Exception
	 */
	public function removeItem(ITrashItem $item) {
		if (!($item instanceof GroupTrashItem)) {
			throw new \LogicException('Trying to remove normal trash item in group folder trash backend');
		}
		$user = $item->getUser();
		[$folderId,] = explode('/', $item->getTrashPath());

		if($item instanceof FakeGroupTrashDir && $item->isFakeDir()) {
			// if item is a fakedir, remove all items within it and stop execution
			return $this->removeAllItemsFromFakeFolder($item);
		}

		$node = $this->getNodeForTrashItem($user, $item);
		$this->checkDeletePermissions($node, $user, $folderId, $item->getOriginalLocation());
		$node->getStorage()->getCache()->remove($node->getInternalPath());
		$this->trashManager->removeItem((int)$folderId, $item->getName(), $item->getDeletedTime());
	}

	public function moveToTrash(IStorage $storage, string $internalPath): bool {
		if ($storage->instanceOfStorage(GroupFolderStorage::class) && $storage->isDeletable($internalPath)) {
			/** @var GroupFolderStorage|Jail $storage */
			$name = basename($internalPath);
			$fileEntry = $storage->getCache()->get($internalPath);
			$folderId = $storage->getFolderId();
			$trashFolder = $this->getTrashFolder($folderId);
			$trashStorage = $trashFolder->getStorage();
			$time = time();
			$trashName = $name . '.d' . $time;
			[$unJailedStorage, $unJailedInternalPath] = $this->unwrapJails($storage, $internalPath);
			$targetInternalPath = $trashFolder->getInternalPath() . '/' . $trashName;
			if ($trashStorage->moveFromStorage($unJailedStorage, $unJailedInternalPath, $targetInternalPath)) {
				$this->trashManager->addTrashItem($folderId, $name, $time, $internalPath, $fileEntry->getId());
				if ($trashStorage->getCache()->getId($targetInternalPath) !== $fileEntry->getId()) {
					$trashStorage->getCache()->moveFromCache($unJailedStorage->getCache(), $unJailedInternalPath, $targetInternalPath);
				}
			} else {
				throw new \Exception("Failed to move groupfolder item to trash");
			}
			return true;
		} else {
			return false;
		}
	}

	private function unwrapJails(IStorage $storage, string $internalPath): array {
		$unJailedInternalPath = $internalPath;
		$unJailedStorage = $storage;
		while ($unJailedStorage->instanceOfStorage(Jail::class)) {
			$unJailedStorage = $unJailedStorage->getWrapperStorage();
			if ($unJailedStorage instanceof Jail) {
				$unJailedInternalPath = $unJailedStorage->getUnjailedPath($unJailedInternalPath);
			}
		}
		return [$unJailedStorage, $unJailedInternalPath];
	}

	private function userHasAccessToFolder(IUser $user, int $folderId): bool {
		$folders = $this->folderManager->getFoldersForUser($user);
		$folderIds = array_map(function (array $folder): int {
			return $folder['folder_id'];
		}, $folders);
		return in_array($folderId, $folderIds);
	}

	private function userHasAccessToPath(IUser $user, string $path, int $permission = Constants::PERMISSION_READ): bool {
		$activePermissions = $this->aclManagerFactory->getACLManager($user)
			->getACLPermissionsForPath('__groupfolders/' . ltrim($path, '/'));
		return (bool)($activePermissions & $permission);
	}

	private function getNodeForTrashItem(IUser $user, ITrashItem $trashItem): ?Node {
		[$folderId, $path] = explode('/', $trashItem->getTrashPath(), 2);
		$pathAddition = '.d' . $trashItem->getDeletedTime();
		if(strpos($path, '/') !== false) {
			$path = substr_replace($path, $pathAddition, strpos($path, '/'), 0);
		} else {
			$path .= $pathAddition;
		}
		$folders = $this->folderManager->getFoldersForUser($user);
		foreach ($folders as $groupFolder) {
			if ($groupFolder['folder_id'] === (int)$folderId) {
				$trashRoot = $this->getTrashFolder((int)$folderId);
				try {
					$node = $trashRoot->get($path);
					if (!$this->userHasAccessToPath($user, $folderId . '/' . $trashItem->getOriginalLocation())) {
						return null;
					}
					return $node;
				} catch (NotFoundException $e) {
					return null;
				}
			}
		}
		return null;
	}

	private function getTrashFolder(int $folderId): Folder {
		try {
			return $this->appFolder->get('trash/' . $folderId);
		} catch (NotFoundException $e) {
			/** @var Folder $trashRoot */
			$trashRoot = $this->appFolder->nodeExists('trash') ? $this->appFolder->get('trash') : $this->appFolder->newFolder('trash');
			return $trashRoot->newFolder((string)$folderId);
		}
	}

	/**
	 * @return list<ITrashItem>
	 */
	private function getTrashForFolders(IUser $user, array $folders): array {
		$folderIds = array_map(function (array $folder): int {
			return $folder['folder_id'];
		}, $folders);
		$rows = $this->trashManager->listTrashForFolders($folderIds);
		$indexedRows = [];
		foreach ($rows as $row) {
			$key = $row['folder_id'] . '/' . $row['name'] . '/' . $row['deleted_time'];
			$indexedRows[$key] = $row;
		}
		$items = [];
		foreach ($folders as $folder) {
			$folderId = $folder['folder_id'];
			$mountPoint = $folder['mount_point'];
			$trashFolder = $this->getTrashFolder($folderId);
			$content = $trashFolder->getDirectoryListing();
			foreach ($content as $item) {
				/** @var \OC\Files\Node\Node $item */
				$pathParts = pathinfo($item->getName());
				$timestamp = (int)substr($pathParts['extension'], 1);
				$name = $pathParts['filename'];
				$key = $folderId . '/' . $name . '/' . $timestamp;
				$originalLocation = isset($indexedRows[$key]) ? $indexedRows[$key]['original_location'] : '';
				if (!$this->userHasAccessToPath($user, $folderId . '/' . $originalLocation)) {
					continue;
				}
				$info = $item->getFileInfo();
				$info['name'] = $name;
				$items[] = new GroupTrashItem(
					$this,
					$originalLocation,
					$timestamp,
					'/' . $folderId . '/' . $item->getName(),
					$info,
					$user,
					$mountPoint
				);
			}
		}
		return $items;
	}

	public function getTrashNodeById(IUser $user, int $fileId): ?Node {
		try {
			/** @var Folder $trashFolder */
			$trashFolder = $this->appFolder->get('trash');
			$storage = $this->appFolder->getStorage();
			$path = $storage->getCache()->getPathById($fileId);
			if (!$path) {
				return null;
			}
			$absolutePath = $this->appFolder->getMountPoint()->getMountPoint() . $path;
			$relativePath = $trashFolder->getRelativePath($absolutePath);
			[, $folderId, $nameAndTime] = explode('/', $relativePath);
			$trashItem = $this->trashManager->getTrashItemByFileId($fileId);
			$originalPath = $folderId . '/' . ($trashItem ? $trashItem['original_location'] : '/');
			if ($this->userHasAccessToFolder($user, (int)$folderId) && $this->userHasAccessToPath($user, $originalPath)) {
				return $trashFolder->get($relativePath);
			} else {
				return null;
			}
		} catch (NotFoundException $e) {
			return null;
		}
	}

	public function cleanTrashFolder(int $folderid): void {
		$trashFolder = $this->getTrashFolder($folderid);

		if (!($trashFolder instanceof Folder)) {
			return;
		}

		foreach ($trashFolder->getDirectoryListing() as $node) {
			$node->delete();
		}

		$this->trashManager->emptyTrashbin($folderid);
	}

	public function expire(Expiration $expiration): array {
		$size = 0;
		$count = 0;
		$folders = $this->folderManager->getAllFoldersWithSize($this->rootFolder->getMountPoint()->getNumericStorageId());
		foreach ($folders as $folder) {
			$folderId = $folder['id'];
			$trashItems = $this->trashManager->listTrashForFolders([$folderId]);

			// calculate size of trash items
			$sizeInTrash = 0;
			$trashFolder = $this->getTrashFolder($folderId);
			$nodes = []; // cache
			foreach ($trashItems as $groupTrashItem) {
				$nodeName = $groupTrashItem['name'] . '.d' . $groupTrashItem['deleted_time'];
				$nodes[$nodeName] = $node = $trashFolder->get($nodeName);
				$sizeInTrash += $node->getSize();
			}
			foreach ($trashItems as $groupTrashItem) {
				if ($expiration->isExpired($groupTrashItem['deleted_time'], $folder['quota'] > 0 && $folder['quota'] < ($folder['size'] + $sizeInTrash))) {
					try {
						$nodeName = $groupTrashItem['name'] . '.d' . $groupTrashItem['deleted_time'];
						$node = $nodes[$nodeName];
						$size += $node->getSize();
						$count += 1;
					} catch (NotFoundException $e) {
						$this->trashManager->removeItem($folderId, $groupTrashItem['name'], $groupTrashItem['deleted_time']);
						continue;
					}
					if ($node->getStorage()->unlink($node->getInternalPath()) === false) {
						throw new \Exception('Failed to remove item from trashbin');
					}
					$node->getStorage()->getCache()->remove($node->getInternalPath());
					$this->trashManager->removeItem($folderId, $groupTrashItem['name'], $groupTrashItem['deleted_time']);
					if (!is_null($groupTrashItem['file_id']) && !is_null($this->versionsBackend)) {
						$this->versionsBackend->deleteAllVersionsForFile($folderId, $groupTrashItem['file_id']);
					}
				} else {
					break;
				}
			}
		}
		return [$count, $size];
	}
}
