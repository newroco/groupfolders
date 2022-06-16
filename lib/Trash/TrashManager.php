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

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class TrashManager {
	private IDBConnection $connection;

	public function __construct(IDBConnection $connection) {
		$this->connection = $connection;
	}

	/**
	 * @param int[] $folderIds
	 * @return array
	 */
	public function listTrashForFolders(array $folderIds): array {
		$query = $this->connection->getQueryBuilder();
		$query->select(['trash_id', 'name', 'deleted_time', 'original_location', 'folder_id', 'file_id'])
			->from('group_folders_trash')
			->orderBy('deleted_time')
			->where($query->expr()->in('folder_id', $query->createNamedParameter($folderIds, IQueryBuilder::PARAM_INT_ARRAY)));
		return $query->executeQuery()->fetchAll();
	}

	public function groupTrashFolderIds(): array {
		$query = $this->connection->getQueryBuilder();
		$query->selectDistinct(['folder_id'])
			->from('group_folders_trash');

		return array_map(function($folder) {
			return (int) $folder['folder_id'];
		}, $query->executeQuery()->fetchAll());
	}

	public function getTimestampForFolder($folderId, $location = null): string
	{
		$query = $this->connection->getQueryBuilder();
		$query->select(['deleted_time'])
			->from('group_folders_trash')
			->where($query->expr()->eq('folder_id', $query->createNamedParameter($folderId, IQueryBuilder::PARAM_INT)));

		if(isset($location) && $location !== '') {
			$slashLimit = substr_count($location, '/');
			$query->andWhere($query->expr()->like('original_location', $query->createNamedParameter($location . '%')))
				->andWhere($query->expr()->notLike('original_location', $query->createNamedParameter(str_repeat('%/', $slashLimit))));
		}

		$query->orderBy('deleted_time', 'DESC')
			->setMaxResults(1);

		return $query->executeQuery()->fetch()['deleted_time'];
	}

	public function getItemsForFolder(int $folderId, string $originalLocation, int $slashLimit = 1)
	{
		$query = $this->connection->getQueryBuilder();
		$query->select(['*'])
			->from('group_folders_trash')
			->where($query->expr()->eq('folder_id', $query->createNamedParameter($folderId, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->like('original_location', $query->createNamedParameter($originalLocation . '%')))
			->andWhere($query->expr()->notLike('original_location', $query->createNamedParameter(str_repeat('%/', $slashLimit) . '%')));

		return $query->executeQuery()->fetchAll();
	}

	public function getNeededFolders(int $folderId, string $originalLocation, int $slashLimit = 1)
	{
		$query = $this->connection->getQueryBuilder();
		$query->selectDistinct(['original_location'])
			->from('group_folders_trash')
			->where($query->expr()->eq('folder_id', $query->createNamedParameter($folderId, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->like('original_location', $query->createNamedParameter($originalLocation . '%')))
			->andWhere($query->expr()->like('original_location', $query->createNamedParameter(str_repeat('%/', $slashLimit) . '%')))
			->andWhere($query->expr()->notLike('original_location', $query->createNamedParameter(str_repeat('%/', $slashLimit + 1) . '%')));

		$folders = array_map(function($location) {
			$locationArr = explode('/', $location['original_location']);

			return $locationArr[array_key_last($locationArr) - 1];
		}, $query->executeQuery()->fetchAll());

		return array_unique($folders);
	}

	public function addTrashItem(int $folderId, string $name, int $deletedTime, string $originalLocation, int $fileId): void {
		$query = $this->connection->getQueryBuilder();
		$query->insert('group_folders_trash')
			->values([
				'folder_id' => $query->createNamedParameter($folderId, IQueryBuilder::PARAM_INT),
				'name' => $query->createNamedParameter($name),
				'deleted_time' => $query->createNamedParameter($deletedTime, IQueryBuilder::PARAM_INT),
				'original_location' => $query->createNamedParameter($originalLocation),
				'file_id' => $query->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)
			]);
		$query->executeStatement();
	}

	public function getTrashItemByFileId(int $fileId): array {
		$query = $this->connection->getQueryBuilder();
		$query->select(['trash_id', 'name', 'deleted_time', 'original_location', 'folder_id'])
			->from('group_folders_trash')
			->where($query->expr()->eq('file_id', $query->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
		return $query->executeQuery()->fetch();
	}

	public function removeItem(int $folderId, string $name, int $deletedTime): void {
		$query = $this->connection->getQueryBuilder();
		$query->delete('group_folders_trash')
			->where($query->expr()->eq('folder_id', $query->createNamedParameter($folderId, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('name', $query->createNamedParameter($name)))
			->andWhere($query->expr()->eq('deleted_time', $query->createNamedParameter($deletedTime, IQueryBuilder::PARAM_INT)));
		$query->executeStatement();
	}

	public function emptyTrashbin(int $folderId): void {
		$query = $this->connection->getQueryBuilder();
		$query->delete('group_folders_trash')
			->where($query->expr()->eq('folder_id', $query->createNamedParameter($folderId, IQueryBuilder::PARAM_INT)));
		$query->executeStatement();
	}
}
