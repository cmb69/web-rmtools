<?php
namespace rmtools;
include_once 'Svn.php';

class Storage {
	protected $db = NULL;
	protected $release = NULL;
	protected $dev_branch = NULL;
	protected $release_branch = NULL;
	protected $first_revision = NULL;

	function __construct($release) {
	
		$svn = new Base;
		$this->base = $svn;
		$release = $this->release = $svn->getRelease($release);
		$this->dev_branch = $release['dev_branch'];
		$this->release_branch = $release['release_branch'];
		$this->first_revision = $release['first_revision'];

		$path = DB_PATH . '/' . $this->release_branch . '.sqlite';

		$this->db = sqlite_open($path);

		if (!$this->db) {
			throw new \Exception('Cannot open storage ' . $path);
		}

		if (!static::isInitialized()) {
			static::createStorage();
		}
	}

	function isInitialized() {
		$res = sqlite_query($this->db, "SELECT name FROM sqlite_master WHERE type='table' AND name='revision'");
		return (sqlite_num_rows($res) > 0);
	}

	function createStorage() {
		if (!sqlite_query($this->db, 'CREATE TABLE revision (
			revision VARCHAR(32),
			release VARCHAR(32),
			date VARCHAR(32),
			author VARCHAR(80),
			msg VARCHAR(255),
			status integer,
			comment TEXT,
			news TEXT)')) {
			throw new \Exception('Cannot initialize storage ' . $path);
		}
	}

	function updateRelease() {

		$svn = new Svn;
		$svn->update($this->dev_branch);
		$logxml = $svn->fetchLogFromBranch($this->dev_branch, $this->first_revision);

		if (!$logxml) {
			return FALSE;
		}

		foreach ($logxml->logentry as  $v) {
			$msg = (string) $v->msg;
			$msg = substr(substr($msg, 0, strpos($msg . "\n", "\n")), 0, 80);
			$rev =  (string) $v['revision'];
			$author = (string) $v->author;
			$date = (string) $v->date;

			$res = sqlite_query($this->db, "SELECT status FROM revision WHERE revision='" . $rev . "'");

			if ($res && sqlite_num_rows($res) > 0) {
				$row = sqlite_fetch_array($res);
				if ($row && empty($row['author'])) {
					$res = sqlite_query($this->db, "UPDATE revision SET author='" . sqlite_escape_string($author) .
						"' WHERE revision='" . sqlite_escape_string($rev) . "'");
					if (!$res) {
						Throw new \Exception('Update query failed for ' . $rev);
					}
				}
			} else {
				$res = sqlite_query($this->db, "INSERT INTO revision (revision, date, author, status, msg, comment, news)
						VALUES('$rev' ,'" . $date . "','" . $author . "', 0, '" . sqlite_escape_string($msg) . "', '', '');");
					if (!$res) {
						Throw new \Exception('Insert query failed for ' . $rev);
					}
			}
		}
		$this->release['last_revision'] = $this->getLatestRevision();
		$this->base->setLatestRevisionForRelease($this->release['name'], $this->release['last_revision']);
		$this->release['last_update'] = date(DATE_RFC822);
		$this->base->setLastUpdateForRelease($this->release['name'], $this->release['last_update']);
	}

	function createSnapshot($filename = FALSE, $force = FALSE) {
		if ($filename && !is_dir(dirname($filename))) {
			throw new \Exception('Invalid filename ' . $filename);
		}

		if (!$filename) {
			$filename = SNAPS_PATH . '/php-' . $this->release['name'] . '-src-' . date("YmdHis") . '.zip';
		}

		$latest_revision = $this->getLatestRevision();
		if ($this->release['last_revision'] == $latest_revision && !$force) {
			return TRUE;
		}

		$tmpname = tempnam(sys_get_temp_dir(), 'rmtools');
		$tmpname_dir = $tmpname . '.dir';

		$svn = new Svn;
		$svn->export($this->release_branch, $tmpname_dir);

		$odir = getcwd();
		chdir($tmpname_dir);
		if (!$filename) {
			$snaps_archive_name = SNAPS_PATH . '/test.zip';
		} else {
			$snaps_archive_name = $filename;
		}

		$now = date(DATE_RFC822);
		$text = "
PHP source snapshot generated on $now. The last revision in this snap is
$latest_revision";

		file_put_contents("SNAPSHOT.txt", $text);
		$cmd = "zip -r $snaps_archive_name *";
		exec($cmd);
		chdir($odir);

		if (!file_exists($snaps_archive_name)) {
			throw new \Exception('Fail to create archive ' . $snaps_archive_name);
		}

		$this->base->setLatestRevisionForRelease($this->release['name'], $latest_revision);
		$this->release['last_revision'] = $latest_revision;

		return $filename;
	}

	function getLatestRevision() {
		 ;
		$res = sqlite_query($this->db, 'SELECT MAX(revision) as revision FROM revision WHERE release=' . "'" . $this->release['name'] . "'", SQLITE_ASSOC);
		if ($res && sqlite_num_rows($res) > 0) {
			$latest_rev = sqlite_fetch_array($res);
			return $latest_rev['revision'];
		}
		return NULL;
	}

	function getAll() {
		/* Test if we actually have a separate branch for the release phases */
		if ($this->release['release_branch'] == $this->release['dev_branch']) {
			return NULL;
		}
		$res = sqlite_query($this->db, 'SELECT * FROM revision ORDER by revision', SQLITE_ASSOC);
		if ($res && sqlite_num_rows($res) > 0) {
			return sqlite_fetch_all($res);
		}
		return NULL;
	}

	function getOne($revision) {
		$res = sqlite_query($this->db, 'SELECT * FROM revision WHERE revision=' . (integer) $revision, SQLITE_ASSOC);
		if ($res && sqlite_num_rows($res) > 0) {
			return sqlite_fetch_array($res);
		}
		return NULL;
	}

	function updateRevision($revision) {
		$error = FALSE;
		if (!isset($revision['status']) || !isset($revision['comment']) || !isset($revision['news']) || ((int)$revision['revision'] < $this->release['first_revision'])) {
			Throw new \Exception('Invalid revision ' . $revision);
		}

		$sql = "UPDATE revision
			set status=" . $revision['status'] . ", comment='" . sqlite_escape_string($revision['comment']) . "', news='" . sqlite_escape_string($revision['news']) . "' WHERE revision=" . (integer)$revision['revision'];

		$res = sqlite_query($this->db, $sql);
		if ($res) {
			return TRUE;
		} else {
			Throw new \Exception('Failed to update revision ' . $revision);
		}
		
		return FALSE;
	}

	function exportAsJson() {
		$log = $this->getAll();
		if ($log) {
			$json = new \StdClass;
			$json->totalRecords = count($log);
			$json->data = $log;
			return json_encode($json);
		}
	}
}