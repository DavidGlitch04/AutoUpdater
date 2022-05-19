<?php

declare(strict_types=1);

namespace davidglitch04\PluginUpdater\Utils;

use davidglitch04\PluginUpdater\UpdateGenerator;
use pocketmine\Server;
use function array_key_exists;
use function date;
use function end;
use function explode;
use function intval;
use function is_dir;
use function mkdir;
use function rename;
use function rmdir;
use function scandir;
use function sizeof;
use function str_replace;
use function strtolower;
use function substr;
use function unlink;

class Utils {

    /**
     * @param UpdateGenerator $generator
     * @param array $cache
     * @return void
     */
	public static function handleUpdateInfo(UpdateGenerator $generator, array $data) : void {
		Server::getInstance()->getLogger()->debug("Handling latest update data.");
		if ($data["Error"] !== '') {
			Server::getInstance()->getLogger()->warning("Failed to get latest update data, Error: " . $data["Error"] . " Code: " . $data["httpCode"]);
			return;
		}
		if (array_key_exists("version", $data["Response"]) && array_key_exists("time", $data["Response"]) && array_key_exists("link", $data["Response"])) {
			$update = Utils::compareVersions(strtolower($generator->getVersion()), strtolower($data["Response"]["version"]));
			if ($update == 0) {
				Server::getInstance()->getLogger()->debug("Plugin up-to-date !");
				return;
			}
			if ($update > 0) {
				$lines = explode("\n", $data["Response"]["patch_notes"]);
				Server::getInstance()->getLogger()->warning("--- UPDATE AVAILABLE ---");
				Server::getInstance()->getLogger()->warning("§cVersion     :: " . $data["Response"]["version"]);
				Server::getInstance()->getLogger()->warning("§bReleased on :: " . date("d-m-Y", intval($data["Response"]["time"])));
				Server::getInstance()->getLogger()->warning("§aPatch Notes :: " . $lines[0]);
				for ($i = 1; $i < sizeof($lines); $i++) {
					Server::getInstance()->getLogger()->warning("                §c" . $lines[$i]);
				}
				Server::getInstance()->getLogger()->warning("§dUpdate Link :: " . $data["Response"]["link"]);
			} else {
				if ($update < 0) {
					Server::getInstance()->getLogger()->debug("Running a build not yet released, this can cause un intended side effects (including possible data loss)");
				}
				return;
			}
            if ($generator->isEnable()){
                Server::getInstance()->getLogger()->warning("§cDownloading & Installing Update, please do not abruptly stop server/plugin.");
                Server::getInstance()->getLogger()->debug("Begin download of new update from '" . $data["Response"]["download_link"] . "'.");
                Utils::downloadUpdate($generator, $data["Response"]["download_link"]);
            }
		} else {
			Server::getInstance()->getLogger()->warning("Failed to verify update data/incorrect format provided.");
			return;
		}
	}

	/**
	 * downloadUpdate function
	 */
	protected static function downloadUpdate(UpdateGenerator $generator, string $url) : void {
		$plugin = $generator->getUpdatePlugin();
		@mkdir($plugin->getDataFolder() . "tmp/");
		$path = $plugin->getDataFolder() . "tmp/{$plugin->getDescription()->getName()}.phar";
		Server::getInstance()->getAsyncPool()->submitTask(new DownloadFile($generator, $url, $path));
	}

    /**
     * @param string $base
     * @param string $new
     * @return int
     */
	public static function compareVersions(string $base, string $new) : int {
		$baseParts = explode(".",$base);
		$baseParts[2] = explode("-beta",$baseParts[2])[0];
		if (sizeof(explode("-beta",explode(".",$base)[2])) > 1) {
			$baseParts[3] = explode("-beta",explode(".",$base)[2])[1];
		}
		$newParts = explode(".",$new);
		$newParts[2] = explode("-beta",$newParts[2])[0];
		if (sizeof(explode("-beta",explode(".",$new)[2])) > 1) {
			$newParts[3] = explode("-beta",explode(".",$new)[2])[1];
		}
		if (intval($newParts[0]) > intval($baseParts[0])) {
			return 1;
		}
		if (intval($newParts[0]) < intval($baseParts[0])) {
			return -1;
		}
		if (intval($newParts[1]) > intval($baseParts[1])) {
			return 1;
		}
		if (intval($newParts[1]) < intval($baseParts[1])) {
			return -1;
		}
		if (intval($newParts[2]) > intval($baseParts[2])) {
			return 1;
		}
		if (intval($newParts[2]) < intval($baseParts[2])) {
			return -1;
		}
		if (isset($baseParts[3])) {
			if (isset($newParts[3])) {
				if (intval($baseParts[3]) > intval($newParts[3])) {
					return -1;
				}
				if (intval($baseParts[3]) < intval($newParts[3])) {
					return 1;
				}
			} else {
				return 1;
			}
		}
		return 0;
	}

    /**
     * @param UpdateGenerator $generator
     * @param string $path
     * @param int $status
     * @return void
     */
	public static function handleDownload(UpdateGenerator $generator, string $path, int $status) : void {
        $plugin = $generator->getUpdatePlugin();
		Server::getInstance()->getLogger()->debug("Update download complete, at '" . $path . "' with status '" . $status . "'");
		if ($status !== 200) {
			Server::getInstance()->getLogger()->warning("Received status code '" . $status . "' when downloading update, update cancelled.");
			Utils::rmalldir($plugin->getDataFolder() . "/tmp");
			return;
		}
		@rename($path, Server::getInstance()->getPluginPath() . "/{$plugin->getName()}.phar");
		if (Utils::getFileName($generator->getFile()) === null) {
			Server::getInstance()->getLogger()->debug("Deleting previous {$plugin->getName()} version...");
			Utils::rmalldir($generator->getFile());
			Server::getInstance()->getLogger()->warning("Update complete, restart your server to load the new updated version.");
			return;
		}
		@rename(Server::getInstance()->getPluginPath() . "/" . Utils::getFileName($generator->getFile()), Server::getInstance()->getPluginPath() . "/{$plugin->getDescription()->getName()}.phar.old"); //failsafe i guess.
		Server::getInstance()->getLogger()->warning("Update complete, restart your server to load the new updated version.");
		return;
	}

    /**
     * @param string $dir
     * @return void
     */
	public static function rmalldir(string $dir) : void {
		if ($dir == "" or $dir == "/" or $dir == "C:/") {
			return;
		} //tiny safeguard.
		$tmp = scandir($dir);
		foreach ($tmp as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}
			$path = $dir . '/' . $item;
			if (is_dir($path)) {
				Utils::rmalldir($path);
			} else {
				@unlink($path);
			}
		}
		@rmdir($dir);
	}

    /**
     * @param string $path
     * @return array|string|string[]|null
     */
	private static function getFileName(string $path) {
		if (substr($path, 0, 7) !== "phar://") {
			return null;
		}
		$tmp = explode("\\", $path);
		$tmp = end($tmp); //requires reference, so cant do all in one
		return str_replace("/","",$tmp);
	}
}
