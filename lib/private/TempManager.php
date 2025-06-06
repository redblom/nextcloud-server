<?php

/**
 * SPDX-FileCopyrightText: 2016-2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2016 ownCloud, Inc.
 * SPDX-License-Identifier: AGPL-3.0-only
 */
namespace OC;

use bantu\IniGetWrapper\IniGetWrapper;
use OCP\Files;
use OCP\IConfig;
use OCP\ITempManager;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

class TempManager implements ITempManager {
	/** @var string[] Current temporary files and folders, used for cleanup */
	protected $current = [];
	/** @var string i.e. /tmp on linux systems */
	protected $tmpBaseDir;
	/** @var LoggerInterface */
	protected $log;
	/** @var IConfig */
	protected $config;
	/** @var IniGetWrapper */
	protected $iniGetWrapper;

	/** Prefix */
	public const TMP_PREFIX = 'oc_tmp_';

	public function __construct(LoggerInterface $logger, IConfig $config, IniGetWrapper $iniGetWrapper) {
		$this->log = $logger;
		$this->config = $config;
		$this->iniGetWrapper = $iniGetWrapper;
		$this->tmpBaseDir = $this->getTempBaseDir();
	}

	private function generateTemporaryPath(string $postFix): string {
		$secureRandom = \OCP\Server::get(ISecureRandom::class);
		$absolutePath = $this->tmpBaseDir . '/' . self::TMP_PREFIX . $secureRandom->generate(32, ISecureRandom::CHAR_ALPHANUMERIC);

		if ($postFix !== '') {
			$postFix = '.' . ltrim($postFix, '.');
			$postFix = str_replace(['\\', '/'], '', $postFix);
		}

		return $absolutePath . $postFix;
	}

	public function getTemporaryFile($postFix = ''): string|false {
		$path = $this->generateTemporaryPath($postFix);

		$old_umask = umask(0077);
		$fp = fopen($path, 'x');
		umask($old_umask);
		if ($fp === false) {
			$this->log->warning(
				'Can not create a temporary file in directory {dir}. Check it exists and has correct permissions',
				[
					'dir' => $this->tmpBaseDir,
				]
			);
			return false;
		}

		fclose($fp);
		$this->current[] = $path;
		return $path;
	}

	public function getTemporaryFolder($postFix = ''): string|false {
		$path = $this->generateTemporaryPath($postFix) . '/';

		if (mkdir($path, 0700) === false) {
			$this->log->warning(
				'Can not create a temporary folder in directory {dir}. Check it exists and has correct permissions',
				[
					'dir' => $this->tmpBaseDir,
				]
			);
			return false;
		}

		$this->current[] = $path;
		return $path;
	}

	/**
	 * Remove the temporary files and folders generated during this request
	 */
	public function clean() {
		$this->cleanFiles($this->current);
	}

	/**
	 * @param string[] $files
	 */
	protected function cleanFiles($files) {
		foreach ($files as $file) {
			if (file_exists($file)) {
				try {
					Files::rmdirr($file);
				} catch (\UnexpectedValueException $ex) {
					$this->log->warning(
						'Error deleting temporary file/folder: {file} - Reason: {error}',
						[
							'file' => $file,
							'error' => $ex->getMessage(),
						]
					);
				}
			}
		}
	}

	/**
	 * Remove old temporary files and folders that were failed to be cleaned
	 */
	public function cleanOld() {
		$this->cleanFiles($this->getOldFiles());
	}

	/**
	 * Get all temporary files and folders generated by oc older than an hour
	 *
	 * @return string[]
	 */
	protected function getOldFiles() {
		$cutOfTime = time() - 3600;
		$files = [];
		$dh = opendir($this->tmpBaseDir);
		if ($dh) {
			while (($file = readdir($dh)) !== false) {
				if (substr($file, 0, 7) === self::TMP_PREFIX) {
					$path = $this->tmpBaseDir . '/' . $file;
					$mtime = filemtime($path);
					if ($mtime < $cutOfTime) {
						$files[] = $path;
					}
				}
			}
		}
		return $files;
	}

	/**
	 * Get the temporary base directory configured on the server
	 *
	 * @return string Path to the temporary directory or null
	 * @throws \UnexpectedValueException
	 */
	public function getTempBaseDir() {
		if ($this->tmpBaseDir) {
			return $this->tmpBaseDir;
		}

		$directories = [];
		if ($temp = $this->config->getSystemValue('tempdirectory', null)) {
			$directories[] = $temp;
		}
		if ($temp = $this->iniGetWrapper->get('upload_tmp_dir')) {
			$directories[] = $temp;
		}
		if ($temp = getenv('TMP')) {
			$directories[] = $temp;
		}
		if ($temp = getenv('TEMP')) {
			$directories[] = $temp;
		}
		if ($temp = getenv('TMPDIR')) {
			$directories[] = $temp;
		}
		if ($temp = sys_get_temp_dir()) {
			$directories[] = $temp;
		}

		foreach ($directories as $dir) {
			if ($this->checkTemporaryDirectory($dir)) {
				return $dir;
			}
		}

		$temp = tempnam(__DIR__, '');
		if (file_exists($temp)) {
			unlink($temp);
			return dirname($temp);
		}
		throw new \UnexpectedValueException('Unable to detect system temporary directory');
	}

	/**
	 * Check if a temporary directory is ready for use
	 *
	 * @param mixed $directory
	 * @return bool
	 */
	private function checkTemporaryDirectory($directory) {
		// suppress any possible errors caused by is_writable
		// checks missing or invalid path or characters, wrong permissions etc
		try {
			if (is_writable($directory)) {
				return true;
			}
		} catch (\Exception $e) {
		}
		$this->log->warning('Temporary directory {dir} is not present or writable',
			['dir' => $directory]
		);
		return false;
	}

	/**
	 * Override the temporary base directory
	 *
	 * @param string $directory
	 */
	public function overrideTempBaseDir($directory) {
		$this->tmpBaseDir = $directory;
	}
}
