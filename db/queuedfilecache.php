<?php
/**
* ownCloud - App Template Example
*
* @author Bernhard Posselt
* @copyright 2012 Bernhard Posselt nukeawhale@gmail.com
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

namespace OCA\MultiInstance\Db;


use \OCA\AppFramework\Db\Entity;


class QueuedFileCache extends Entity {

	public $storage;
	public $path;
	public $pathHash;
	public $parentStorage;
	public $parentPath;
	public $name;
	public $mimetype;
	public $mimepart;
	public $size;
	public $mtime;
	public $encrypted;
	public $etag;
	public $destinationLocation;

	public function __construct($storage, $path, $pathHash, $parentStorage, $parentPath, $name, $mimetype, $mimepart, $size, $mtime, $encrypted, $etag, $destinationLocation){
		$this->setStorage($storage);
		$this->setPath($path);
		$this->setPathHash($pathHash);
		$this->setParentStorage($parentStorage);
		$this->setParentPath($parentPath);
		$this->setName($name);
		$this->setMimetype($mimetype);
		$this->setMimepart($mimepart);
		$this->setSize($size);
		$this->setMtime($mtime);
		$this->setEncrypted($encrypted);
		$this->setEtag($etag);
		$this->setPermissions($permissions);

	}

}
