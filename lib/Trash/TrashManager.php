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
		## Prepare the query builders
		# Main query builder
		$query = $this->connection->getQueryBuilder();
		# Nested query builder
		$subQuery = $this->connection->getQueryBuilder();

		# Build the nested query: Get distinct folder_ids from the trash folders
		$distinctTrashFolderIds = $subQuery->selectDistinct([
			'ogft.folder_id'
		])->from(
			'group_folders_trash',
			'ogft'
		)->getSQL();

		# Create the main query
		$folders = $query->selectDistinct([
			'ogf.folder_id',
			'ogf.mount_point'
		])->from(
			'group_folders',
			'ogf'
		)->join(
			'ogf',
			'group_folders',
			'ogf_clone',
			$query->createFunction(
				"regexp_replace(ogf.mount_point::text, '\\\\', '/', 'g') = split_part(
					regexp_replace(ogf_clone.mount_point::text, '\\\\', '/', 'g'),
					'/',
					1
				)"
			)
		)->where(
			$query->expr()->in(
				'ogf_clone.folder_id',
				$query->createFunction(
					'(' .$distinctTrashFolderIds .')'
				)
			)
		);

		# Execute and return the result
		return $folders->executeQuery()->fetchAll();

		// return array_map(
		// 	function($folder) {
		// 		return [
		// 			'folder_id' 	=> (int) $folder['folder_id'],
		// 			'root_folder'	=> $folder['root_folder']
		// 		];
		// 	},
		// 	$result
		// );
	}

	public function getTimestampForFolder($folderId, $location = null): string
	{
		# Main query builder
		$query = $this->connection->getQueryBuilder();
		# Nested query builder
		$subQuery = $this->connection->getQueryBuilder();


		# Build the nested query: Get mount point or folder with specific id
		$folderMountPoint = $subQuery->select([
			'mount_point'
		])->from('group_folders')->where(
			$query->expr()->eq(
				'folder_id',
				$query->createNamedParameter($folderId, IQueryBuilder::PARAM_INT)
			)
		)->getSQL();

		$query->select([
			'ogft.deleted_time'
		])->from('group_folders_trash', 'ogft')->join(
			'ogft',
			'group_folders',
			'ogf',
			'ogft.folder_id = ogf.folder_id'
		)->where(
			$query->createFunction(
				"split_part(
					regexp_replace(ogf.mount_point::text, '\\\\', '/', 'g'),
					'/',
					1
				) = (" . $folderMountPoint . ")"
			)
		)->orWhere(
			"ogf.mount_point = (" . $folderMountPoint . ")"
		);

		// if(isset($location) && $location !== '') {
		// 	$slashLimit = substr_count($location, '/');
		// 	$query->andWhere($query->expr()->like('original_location', $query->createNamedParameter($location . '%')))
		// 		->andWhere($query->expr()->notLike('original_location', $query->createNamedParameter(str_repeat('%/', $slashLimit))));
		// }

		// $query->orderBy('deleted_time', 'DESC')
		// 	->setMaxResults(1);

		// $sql = $query->getSQL();

		return $query->executeQuery()->fetch()['deleted_time'] ?? '-1';
	}

	public function getItemsForFolder(int $folderId, string $relativeLocation, int $slashLimit = 1, array $wantedFields = null)
	{
		$query = $this->connection->getQueryBuilder();

		# Query the trash table for all items whose name is related to their original location, and have no child items (it means they are NOT folders),
		$folderIdParam = $query->createNamedParameter($folderId, IQueryBuilder::PARAM_INT);
		$fileLocationString = ($relativeLocation === '') ?
			"ogft.name" :
			"CONCAT(" . $query->createNamedParameter($relativeLocation, IQueryBuilder::PARAM_STR) . "::text, '/', ogft.name)";

		$startsWithCondition = $query->createFunction('starts_with(ogft2.original_location, ogft.original_location)');

		$query->select($wantedFields ?? ['ogft.*'])
			->from('group_folders_trash', 'ogft')
			->join('ogft', 'group_folders_trash', 'ogft2', $startsWithCondition)
			->where($query->expr()->eq('ogft.folder_id', $folderIdParam))
			->andWhere(
				$query->expr()->like(
					'ogft.original_location',
					$query->createFunction($fileLocationString)
				))
			->groupBy('ogft.trash_id')
			->having($query->createFunction('COUNT(ogft2.*) <= 1'));

		return $query->executeQuery()->fetchAll();
	}

	public function getNeededFolders(int $folderId, string $absolutePath, string $relativePath)
	{
		$folders 				= [];
		$activeFolders 			= $this->connection->getQueryBuilder();
		$trashFolders 			= $this->connection->getQueryBuilder();

		# Get the number of the slice we need when exploding the mount point in SQL\
		# To do this, we explode the string by '/' to count the number of folders in the mount point
		# We need to get the last "folder" (or slice), so we count the number of items in the resulting array
		$sliceNumber = count(explode('/', $absolutePath));


		$originalFolderMountPoint = $activeFolders->createFunction(
			"SELECT regexp_replace(mount_point::text, '\\\\', '/', 'g') FROM oc_group_folders WHERE folder_id = :folder_id"
		);
		$originalFolderMountPointNoReplace = $activeFolders->createFunction(
			"SELECT mount_point FROM oc_group_folders WHERE folder_id = :folder_id"
		);
		$replace = $activeFolders->createFunction(
			"REPLACE(regexp_replace(ogf.mount_point::text, '\\\\', '/', 'g'), CONCAT((" . $originalFolderMountPoint . "),'/'), '')"
		);

		$slashesNumber = $activeFolders->createFunction("
			CHAR_LENGTH( " . $replace . ") -
			CHAR_LENGTH(REPLACE(
				" . $replace . ",
				'/',
				'')
			) < 1
		");

		$activeFolders
			->selectDistinct('ogf.folder_id')
			->selectAlias(
				$activeFolders->createFunction(
					"reverse(split_part(
						reverse(regexp_replace(ogf.mount_point::text, '\\\\', '/', 'g')),
						'/',
						1
					))"
				),
				'mount_point'
			)
			->from('group_folders', 'ogf')
			->join(
				'ogf',
				'group_folders_trash',
				'ogft',
				'ogf.folder_id = ogft.folder_id'
			)
			->where(
				$activeFolders->createFunction(
					"starts_with(
						mount_point,
						(" . $originalFolderMountPointNoReplace . ")
					)"
				)
			)
			->andWhere('ogf.folder_id != :folder_id')
			->andWhere($slashesNumber )
			->groupBy('ogf.folder_id')
			->having(
				$activeFolders->createFunction('count(ogft.*) > 0')
			)
			->setParameters([
				'folder_id'	=> $folderId
			]);

		$activeFoldersArray = $activeFolders->executeQuery()->fetchAll();

		$folders = \array_merge($folders, $activeFoldersArray);

		$inputtedSliceNo = ($sliceNumber > 1) ? $sliceNumber - 1 : $sliceNumber;

		$mountPointAlias = $trashFolders->createFunction(
			"split_part(
				regexp_replace(original_location::text, '\\\\', '/', 'g'),
				'/',
				:slice_number
			)"
		);

		$originalLocationComparator = ($relativePath === '') ?
			$trashFolders->createFunction("original_location LIKE '%/%'") :
			$trashFolders->createFunction("original_location LIKE CONCAT(:absolute_path::text, '/%/%')");

		$trashFolders->selectDistinct(
			'folder_id'
		)->selectAlias(
			$mountPointAlias,
			'mount_point'
		)->from(
			'group_folders_trash'
		)->where(
			'folder_id = :folder_id'
		)->andWhere(
			$originalLocationComparator
		)->setParameters([
			'folder_id' 	=> $folderId,
			'slice_number'	=> $inputtedSliceNo,
			'absolute_path' => $relativePath
		]);

		$query = $trashFolders->getSQL();

		$trashFoldersArray = $trashFolders->executeQuery()->fetchAll();

		$folders = array_merge($folders, $trashFoldersArray);

		return $folders;

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
