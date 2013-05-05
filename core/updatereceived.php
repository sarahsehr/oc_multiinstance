<?php

/**
* ownCloud - MultiInstance App
*
* @author Sarah Jones
* @copyright 2013 Sarah Jones sarah.e.p.jones@gmail.com 
*
* This library is free software; you can redistribute it and/or
* modify it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE
* License as published by the Free Software Foundation; either
* version 3 of the License, or any later version.
*
* This library is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
* GNU AFFERO GENERAL PUBLIC LICENSE for more details.
*
* You should have received a copy of the GNU Affero General Public
* License along with this library.  If not, see <http://www.gnu.org/licenses/>.
*
*/

namespace OCA\MultiInstance\Core;

use \OCA\AppFramework\Db\DoesNotExistException;

use OCA\MultiInstance\Db\UserUpdate;
use \OC_User;
use OCA\Friends\Db\Friendship;
use OCA\MultiInstance\Db\QueuedFriendship;
use OCA\MultiInstance\Db\QueuedUser;

use OCA\MultiInstance\Lib\MILocation;
use \OC\Files\Cache\Cache;
use OCA\MultiInstance\Db\QueuedFileCache;
use OCA\MultiInstance\Db\FilecacheUpdate;

/* Methods for updating instance db rows based on received rows */
class UpdateReceived {
	

	private $api; 
	private $receivedUserMapper;
	private $userUpdateMapper;
	private $receivedFriendshipMapper;
	private $userFacebookIdMapper;
	private $receivedUserFacebookIdMapper;
	private $friendshipMapper;
	private $queuedFriendshipMapper;
	private $queuedUserMapper;
	private $locationMapper;
	private $receivedFilecacheMapper;
	private $filecacheUpdateMapper;


	/**
	 * @param API $api: an api wrapper instance
	 */
	public function __construct($api, $receivedUserMapper, $userUpdateMapper, $receivedFriendshipMapper, $userFacebookIdMapper, $receivedUserFacebookIdMapper, $friendshipMapper, $queuedFriendshipMapper, $queuedUserMapper, $locationMapper, $receivedFilecacheMapper, $filecacheUpdateMapper){
		$this->api = $api;
		$this->receivedUserMapper = $receivedUserMapper;
		$this->userUpdateMapper = $userUpdateMapper;
		$this->receivedFriendshipMapper = $receivedFriendshipMapper;
		$this->userFacebookIdMapper = $userFacebookIdMapper;
		$this->receivedUserFacebookIdMapper = $receivedUserFacebookIdMapper;
		$this->friendshipMapper = $friendshipMapper;
		$this->queuedFriendshipMapper = $queuedFriendshipMapper;
		$this->queuedUserMapper = $queuedUserMapper;
		$this->locationMapper = $locationMapper;
		$this->receivedFilecacheMapper = $receivedFilecacheMapper;
		$this->filecacheUpdateMapper = $filecacheUpdateMapper;
	}


	public function updateUsersWithReceivedUsers() {
		$receivedUsers = $this->receivedUserMapper->findAll();		

		foreach ($receivedUsers as $receivedUser){
			$uid = $receivedUser->getUid();
			$receivedTimestamp = $receivedUser->getAddedAt();

			$this->api->beginTransaction();
			if ($this->api->userExists($uid)) {

				//TODO: All of this should be wrapped in a try block with a rollback...
				$userUpdate = $this->userUpdateMapper->find($uid);	
				//if this is new
				if ($receivedTimestamp > $userUpdate->getUpdatedAt()) {
					$userUpdate->setUpdatedAt($receivedTimestamp);	
					$this->userUpdateMapper->update($userUpdate);
					$this->api->setPassword($uid, $receivedUser->getPassword());
					//OC_User::setDisplayName($uid, $receivedUser->getDisplayname()); //display name has no hook at this time
					
				}
			}
			else {
				//TODO: createUser will cause the user to be sent back to UCSB, maybe add another parameter?
				$this->api->createUser($uid, 'dummy');  //create user with dummy password; this will create a UserUpdate with current time, not with received time
				$this->api->setPassword($uid, $receivedUser->getPassword());
				$userUpdate = $this->userUpdateMapper->find($uid);
				$userUpdate->setUpdatedAt($receivedTimestamp);	
				$this->userUpdateMapper->update($userUpdate);
			}
			$this->receivedUserMapper->delete($receivedUser);
			$this->api->commit();

		}

	}

	public function updateFriendshipsWithReceivedFriendships($mockLocationMapper=null) {
		$receivedFriendships = $this->receivedFriendshipMapper->findAll();
		
		foreach ($receivedFriendships as $receivedFriendship) {

			$location1 = MILocation::getUidLocation($receivedFriendship->getFriendUid1(), $mockLocationMapper);
			$location2 = MILocation::getUidLocation($receivedFriendship->getFriendUid2(), $mockLocationMapper);
			$centralServer = $this->api->getAppValue('centralServer');
			$thisLocation = $this->api->getAppValue('location');
			
			//If a user from another instance is involved, push info to that instance
			if ($receivedFriendship->getSendingLocation() !== $centralServer) {
				if ($location1 !== $receivedFriendship->getSendingLocation() && $location1 !== $centralServer) {
					$uid = $receivedFriendship->getFriendUid2();
					$userUpdate = $this->userUpdateMapper->find($uid);
					$queuedUser = new QueuedUser($uid, $this->api->getDisplayName($uid), $this->api->getPassword($uid), $userUpdate->getUpdatedAt(), $location1); 
					$queuedFriendship = new QueuedFriendship($receivedFriendship->getFriendUid1(), $receivedFriendship->getFriendUid2(), $receivedFriendship->getUpdatedAt(), $receivedFriendship->getStatus(), $location1, $thisLocation);	

					$this->api->beginTransaction();
					$this->queuedFriendshipMapper->save($queuedFriendship);
					$this->queuedUserMapper->save($queuedUser);
					$this->api->commit();
				}
				if ($location2 !== $receivedFriendship->getSendingLocation() && $location2 !== $centralServer) {
					$uid = $receivedFriendship->getFriendUid1();
					$userUpdate = $this->userUpdateMapper->find($uid);
					$queuedUser = new QueuedUser($uid, $this->api->getDisplayName($uid), $this->api->getPassword($uid), $userUpdate->getUpdatedAt(), $location2); 
					$queuedFriendship = new QueuedFriendship($receivedFriendship->getFriendUid1(), $receivedFriendship->getFriendUid2(), $receivedFriendship->getUpdatedAt(), $receivedFriendship->getStatus(), $location2, $thisLocation);	

					$this->api->beginTransaction();
					$this->queuedFriendshipMapper->save($queuedFriendship);
					$this->queuedUserMapper->save($queuedUser);
					$this->api->commit();
				}
			}

			//TODO: try block with rollback?
			$this->api->beginTransaction();
			try {
				$friendship = $this->friendshipMapper->find($receivedFriendship->getFriendUid1(), $receivedFriendship->getFriendUid2());
				if ($receivedFriendship->getUpdatedAt() > $friendship->getUpdatedAt()) { //if newer than last update
					$friendship->setStatus($receivedFriendship->getStatus());
					$friendship->setUpdatedAt($receivedFriendship->getUpdatedAt());
					$this->friendshipMapper->update($friendship);
				}
			}
			catch (DoesNotExistException $e) {
				$friendship = new Friendship();
				$friendship->setFriendUid1($receivedFriendship->getFriendUid1());
				$friendship->setFriendUid2($receivedFriendship->getFriendUid2());
				$friendship->setStatus($receivedFriendship->getStatus());
				$friendship->setUpdatedAt($receivedFriendship->getUpdatedAt());
				$this->friendshipMapper->insert($friendship);
			}
			$this->receivedFriendshipMapper->delete($receivedFriendship);
			$this->api->commit();
		}
	}


	public function updateUserFacebookIdsWithReceivedUserFacebookIds() {
		$receivedUserFacebookIds = $this->receivedUserFacebookIdMapper->findAll();
	
		foreach ($receivedUserFacebookIds as $receivedUserFacebookId) {
			//TODO: try block with rollback?
			$this->api->beginTransaction();
			try {
				$userFacebookId = $this->userFacebookIdMapper->find($receivedUserFacebookId->getUid());
				//TODO: check if I need to convert to datetimes?
				if ($receivedUserFacebookId->getFriendsSyncedAt() > $userFacebookId->getFriendsSyncedAt()) {
					$this->userFacebookIdMapper->save($receivedUserFacebookId);
				}
			}
			catch (DoesNotExistException $e) {
					$this->userFacebookIdMapper->save($receivedUserFacebookId);
			}
			$this->receivedUserFacebookIdMapper->delete($receivedUserFacebookId);
			$this->api->commit();
		}
	}

	public function updateFilecacheFromReceivedFilecaches() {
		$receivedFilecaches = $this->receivedFilecacheMapper->findAll();
		$dataPath = $this->api->getSystemValue('datadirectory');

		foreach ($receivedFilecaches as $receivedFilecache) {
			$this->api->beginTransaction();

			$fullPath = $dataPath . $receivedFilecache->getStorage();
			$storagePath = "local::". $fullPath;
			$cache = new Cache($storagePath);
			$storageNumericId = $cache->getNumericStorageId();
	
			$mimetypeId = $cache->getMimetypeId($receivedFilecache->getMimetype());
			$state = ($receivedFilecache->getQueueType() === QueuedFileCache::DELETE) ? FilecacheUpdate::DELETED : FilecacheUpdate::VALID;

			$filecache = $cache->get($receivedFilecache->getPath());

			if (empty($filecache)) {  //if new file
				//cp 
				$data = array(  //the rest are derived
					'encrypted' => $receivedFilecache->getEncrypted(),
					'size' => $receivedFilecache->getSize(),
					'mtime' => $receivedFilecache->getMtime(),
					'etag' => $receivedFilecache->getEtag(),
					'mimetype' => $mimetypeId
					
				);
				MILocation::copyFileToDataFolder($this->api, $receivedFilecache->getPath(), $receivedFilecache->getStorage(), $receivedFilecache->sendingLocation());
				$cache->put($receivedFilecache->getPath(), $data);
				$filecache = $cache->get($receivedFilecache->getPath());
				$filecacheUpdate = new FilecacheUpdate(md5($receivedFilecache->getPath()), $receivedFilecache->getStorage(), $receivedFilecache->getAddedAt(), $state);
				$this->filecacheUpdateMapper->insert($filecacheUpdate);
				
			}
			else {  //not new file
				$filecacheUpdate = $this->filecacheUpdateMapper->find(md5($receivedFilecache->getPath()), $receivedFilecache->getStorage());

				if ($receivedFilecache->getAddedAt() > $filecacheUpdate->getUpdatedAt()) { //if updated file
					//cp
					//TODO figure out what this needs to be
					$data = array( 
						'encrypted' => $receivedFilecache->getEncrypted(),
						'size' => $receivedFilecache->getSize(),
						'mtime' => $receivedFilecache->getMtime(),
						'etag' => $receivedFilecache->getEtag(),
						'mimetype' => $mimetypeId
					);
					MILocation::copyFileToDataFolder($this->api, $receivedFilecache->getPath(), $receivedFilecache->getStorage(), $receivedFilecache->sendingLocation());
					$cache->update($filecache['fileid'], $data);
					$filecacheUpdate->setUpdatedAt($receivedFilecache->getAddedAt());
					$filecacheUpdate->setState($state);
					$this->filecacheUpdateMapper->update($filecacheUpdate);
					
				}
			}
			$this->api->commit();


		}
	}

	//TODO create everything for this
	public function updatePermissionsFromReceivedPermissions() {
		$receivedPermissions = $this->receivedPermissionMapper->findAll();

		foreach ($receivedPermissions as $receivedPermission) {
			$dataPath = $this->api->getSystemValue('datadirectory');
			$storagePath = "local::" . $dataPath . $receivedPermission->getUser() . '/';

			$permissions = new Permissions($storagePath);
			$cache = new Cache($storagePath);
			$fileid = $cache->getId($receivedPermission->getPath());

			$this->api->beginTransaction();
			$permission = $permissions->get($fileid, $receivedPermission->getUser());

			if ($permission) {  //permission update
				$permissionUpdate = $this->permissionUpdateMapper->find($fileid, $receivedPermission->getUser());
				if ($receivedPermission->getUpdatedAt() > $permissionUpdate->updatedAt()) {  //update if new data
					$permissions->set($fileid, $receivedPermission->getUser(), $receivedPermission->getPermissions());
					$permissionUpdate->setUpdatedAt($receivedPermission->getUpdated());
					$permissionsUpdate->setState($receivedPermission->getState());
					$this->permissionUpdateMapper->update($permissionUpdate);
				}
			}
			else {  //new permission
				$permissions->set($fileid, $receivedPermission->getUser(), $receivedPermission->getPermissions());
				try {  	//permissions could have been previously deleted, so permissionUpdate may exist
					$permissionUpdate = $this->permissionUpdateMapper->find($fileid, $receivedPermission->getUser());
					$permissionUpdate->setUpdatedAt($receivedPermission->getUpdated());
					$permissionUpdate->setState($receivedPermission->getState());
					$this->permissionUpdateMapper->update($permissionUpdate);
				}	
				catch (DoesNotExistException $e) {
					$permissionUpdate = new PermissionUpdate($fileid, $receivedPermission->getUser(), $receivedPermission->getUpdatedAt(), $receivedPermission->getState());
					$this->permissionUpdateMapper->insert($permissionUpdate);
				}
			}
			if ($receivedPermission->getState() === PermissionUpdate::DELETED) {
				$permissions->remove($fileid, $receivedPermission->getUser());
			}
			$this->receivedPermissionMapper->delete($receivedPermission);  //going to have to be a status on the update, but actually delete the permission
			$this->api->commit();
		}
	}

}
