<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Happyfeet01
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesZip\Service;

use OCA\Files_Sharing\SharedStorage;
use OCA\FilesZip\AppInfo\Application;
use OCA\FilesZip\BackgroundJob\ArchiveCompressJob;
use OCA\FilesZip\Exceptions\ArchiveLimitExceededException;
use OCA\FilesZip\Exceptions\TargetAlreadyExists;
use OCA\FilesZip\Exceptions\UnsupportedArchiveFormatException;
use OCA\FilesZip\Model\ArchiveFormat;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\ITempManager;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Share\IAttributes;
use OCP\Share\IShare;
use RuntimeException;
use Throwable;

final class ArchiveCompressionService {
	private const TEMP_DISK_RESERVE_BYTES = 268435456;
	private const MAX_TREE_DEPTH = 64;
	private const MIN_VOLUME_SIZE_BYTES = 1048576;
	private const MAX_VOLUME_SIZE_BYTES = 1099511627776;
	private const MAX_VOLUME_COUNT = 1000;

	public function __construct(
		private IRootFolder $rootFolder,
		private IUserSession $userSession,
		private IUserManager $userManager,
		private ITempManager $tempManager,
		private IConfig $config,
		private IJobList $jobList,
		private SevenZipBinary $sevenZip,
		private ArchivePathValidator $pathValidator,
		private NotificationService $notificationService,
	) {
	}

	/** @param list<int> $fileIds */
	public function createJob(array $fileIds, string $target, string $formatValue, ?int $volumeSize = null): void {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new RuntimeException('No user session available');
		}

		$format = $this->parseCompressionFormat($formatValue);
		$volumeSize = $this->normalizeVolumeSize($volumeSize, $format);
		$this->assertBackendAvailable($format);
		$userFolder = $this->rootFolder->getUserFolder($user->getUID());
		$target = $this->normalizeTarget($target, $format);
		$this->resolveTargetParent($userFolder, $target, $volumeSize);
		$this->verifyAndGetNodes($userFolder, $fileIds);

		$this->jobList->add(ArchiveCompressJob::class, [
			'uid' => $user->getUID(),
			'fileIds' => $fileIds,
			'target' => $target,
			'format' => $format->value,
			'volumeSize' => $volumeSize,
		]);
		$this->notificationService->sendArchiveCompressionPending($user->getUID(), $target, $format->value);
	}

	/** @param list<int> $fileIds */
	public function compress(string $uid, array $fileIds, string $target, string $formatValue, ?int $volumeSize = null): File {
		$user = $this->userManager->get($uid);
		if ($user === null) {
			throw new RuntimeException('User does not exist');
		}

		$format = $this->parseCompressionFormat($formatValue);
		$volumeSize = $this->normalizeVolumeSize($volumeSize, $format);
		$this->assertBackendAvailable($format);
		$this->userSession->setVolatileActiveUser($user);

		try {
			$userFolder = $this->rootFolder->getUserFolder($uid);
			$target = $this->normalizeTarget($target, $format);
			[$targetParent, $targetName] = $this->resolveTargetParent($userFolder, $target, $volumeSize);
			$nodes = $this->verifyAndGetNodes($userFolder, $fileIds);

			$estimatedBytes = $this->sumNodes($nodes);
			$maxSize = (int)$this->config->getAppValue(Application::APP_NAME, 'max_compress_size', '-1');
			if ($maxSize !== -1 && $estimatedBytes > $maxSize) {
				throw new ArchiveLimitExceededException('Selected files exceed the configured compression limit');
			}
			$this->assertVolumeCountEstimate($estimatedBytes, $volumeSize);

			$workDir = $this->tempManager->getTemporaryFolder('files-zip-compress');
			if ($workDir === false) {
				throw new RuntimeException('Unable to allocate temporary compression directory');
			}
			$stageDir = rtrim($workDir, '/') . '/input';
			if (!@mkdir($stageDir, 0700)) {
				throw new RuntimeException('Unable to create temporary staging directory');
			}

			$this->assertInitialTempCapacity($workDir, $estimatedBytes, $format);
			$stagedBytes = 0;
			$topLevelNames = [];
			foreach ($nodes as $node) {
				$name = $this->safeLocalName($node->getName());
				if (in_array($name, $topLevelNames, true)) {
					throw new RuntimeException('Selected nodes contain duplicate archive names');
				}
				$topLevelNames[] = $name;
				$this->stageNode($node, $stageDir . '/' . $name, $workDir, $stagedBytes, $maxSize, 0);
			}

			$archiveParts = $this->createArchive($workDir, $stageDir, $targetName, $topLevelNames, $format, $volumeSize);
			return $this->copyArchivesToNextcloud($archiveParts, $targetParent, $targetName);
		} finally {
			$this->userSession->setVolatileActiveUser(null);
		}
	}

	private function parseCompressionFormat(string $formatValue): ArchiveFormat {
		$format = ArchiveFormat::tryFrom(strtolower($formatValue));
		if ($format === null) {
			throw new UnsupportedArchiveFormatException('Unsupported archive format');
		}
		return $format;
	}

	private function normalizeVolumeSize(?int $volumeSize, ArchiveFormat $format): ?int {
		if ($volumeSize === null) {
			if ($format === ArchiveFormat::ZIP) {
				throw new UnsupportedArchiveFormatException('ZIP compression without splitting uses the existing ZIP endpoint');
			}
			return null;
		}

		if ($volumeSize < self::MIN_VOLUME_SIZE_BYTES || $volumeSize > self::MAX_VOLUME_SIZE_BYTES) {
			throw new ArchiveLimitExceededException('Multipart archive part size must be between 1 MiB and 1 TiB');
		}
		return $volumeSize;
	}

	private function assertBackendAvailable(ArchiveFormat $format): void {
		if (!$this->sevenZip->isAvailable()) {
			throw new UnsupportedArchiveFormatException(
				$format->value . ' compression requires ' . SevenZipBinary::PATH . ' >= ' . SevenZipBinary::MINIMUM_VERSION,
			);
		}
	}

	private function normalizeTarget(string $target, ArchiveFormat $format): string {
		$target = $this->pathValidator->normalize(ltrim($target, '/'));
		if (!str_ends_with(strtolower($target), $format->extension())) {
			throw new UnsupportedArchiveFormatException('Archive filename does not match the selected format');
		}
		return $target;
	}

	/** @return array{0: Folder, 1: string} */
	private function resolveTargetParent(Folder $userFolder, string $target, ?int $volumeSize = null): array {
		$parentPath = dirname($target);
		$targetName = basename($target);
		$parent = $parentPath === '.' ? $userFolder : $userFolder->get($parentPath);
		if (!$parent instanceof Folder || !$parent->isCreatable()) {
			throw new RuntimeException('Archive destination is not writable');
		}
		$parent->verifyPath($targetName);
		if ($parent->nodeExists($targetName)) {
			throw new TargetAlreadyExists();
		}
		if ($volumeSize !== null) {
			$firstPartName = $targetName . '.001';
			$parent->verifyPath($firstPartName);
			if ($parent->nodeExists($firstPartName)) {
				throw new TargetAlreadyExists();
			}
		}
		return [$parent, $targetName];
	}

	/**
	 * @param list<int> $fileIds
	 * @return list<Node>
	 */
	private function verifyAndGetNodes(Folder $userFolder, array $fileIds): array {
		$nodes = [];
		foreach ($fileIds as $fileId) {
			if (!is_int($fileId)) {
				continue;
			}
			$matches = $userFolder->getById($fileId);
			if ($matches === []) {
				continue;
			}
			$node = array_pop($matches);
			if (!$node instanceof Node || !$node->isReadable()) {
				continue;
			}
			if (!$this->isDownloadAllowed($node)) {
				continue;
			}
			$nodes[] = $node;
		}

		if ($nodes === []) {
			throw new NotFoundException('No readable files were selected');
		}
		return $nodes;
	}

	private function isDownloadAllowed(Node $node): bool {
		$storage = $node->getStorage();
		if (!$node->isShared() || !$storage->instanceOfStorage(SharedStorage::class) || !method_exists(IShare::class, 'getAttributes')) {
			return true;
		}

		/** @var SharedStorage $storage */
		$share = $storage->getShare();
		$hasShareAttributes = $share && $share->getAttributes() instanceof IAttributes;
		return !$hasShareAttributes || $share->getAttributes()->getAttribute('permissions', 'download') !== false;
	}

	/** @param list<Node> $nodes */
	private function sumNodes(array $nodes): int {
		$total = 0;
		foreach ($nodes as $node) {
			$size = $this->sumNode($node, 0);
			if ($size > PHP_INT_MAX - $total) {
				throw new ArchiveLimitExceededException('Selected files exceed the supported integer range');
			}
			$total += $size;
		}
		return $total;
	}

	private function sumNode(Node $node, int $depth): int {
		if ($depth > self::MAX_TREE_DEPTH) {
			throw new ArchiveLimitExceededException('Selected folder tree is nested too deeply');
		}
		if ($node instanceof File) {
			return max(0, (int)$node->getSize());
		}
		if (!$node instanceof Folder) {
			throw new RuntimeException('Unsupported Nextcloud node type');
		}

		$total = 0;
		foreach ($node->getDirectoryListing() as $child) {
			$size = $this->sumNode($child, $depth + 1);
			if ($size > PHP_INT_MAX - $total) {
				throw new ArchiveLimitExceededException('Selected files exceed the supported integer range');
			}
			$total += $size;
		}
		return $total;
	}

	private function assertVolumeCountEstimate(int $inputBytes, ?int $volumeSize): void {
		if ($volumeSize === null) {
			return;
		}
		if ($inputBytes > $volumeSize * self::MAX_VOLUME_COUNT) {
			throw new ArchiveLimitExceededException('Multipart archive would require more than 1000 parts');
		}
	}

	private function assertInitialTempCapacity(string $workDir, int $inputBytes, ArchiveFormat $format): void {
		$free = @disk_free_space($workDir);
		if ($free === false) {
			return;
		}

		$multiplier = $format === ArchiveFormat::TAR_GZ ? 3 : 2;
		if ($inputBytes > intdiv(PHP_INT_MAX - self::TEMP_DISK_RESERVE_BYTES, $multiplier)) {
			throw new ArchiveLimitExceededException('Compression request is too large for safe temporary staging');
		}
		$required = ($inputBytes * $multiplier) + self::TEMP_DISK_RESERVE_BYTES;
		if ($required > $free) {
			throw new ArchiveLimitExceededException('Not enough temporary disk space to create the archive safely');
		}
	}

	private function safeLocalName(string $name): string {
		if (
			$name === ''
			|| $name === '.'
			|| $name === '..'
			|| preg_match('//u', $name) !== 1
			|| preg_match('/[\x00-\x1F\x7F]/u', $name) === 1
			|| str_contains($name, '/')
			|| str_contains($name, '\\')
		) {
			throw new RuntimeException('Selected file has an unsafe local staging name');
		}
		return $name;
	}

	private function stageNode(Node $node, string $destination, string $workDir, int &$stagedBytes, int $maxSize, int $depth): void {
		if ($depth > self::MAX_TREE_DEPTH) {
			throw new ArchiveLimitExceededException('Selected folder tree is nested too deeply');
		}

		if ($node instanceof Folder) {
			if (file_exists($destination) || !@mkdir($destination, 0700)) {
				throw new RuntimeException('Unable to create temporary folder');
			}
			foreach ($node->getDirectoryListing() as $child) {
				$name = $this->safeLocalName($child->getName());
				$this->stageNode($child, $destination . '/' . $name, $workDir, $stagedBytes, $maxSize, $depth + 1);
			}
			return;
		}

		if (!$node instanceof File) {
			throw new RuntimeException('Unsupported Nextcloud node type');
		}
		if (file_exists($destination)) {
			throw new RuntimeException('Temporary archive path collision');
		}

		$source = $node->fopen('rb');
		$output = @fopen($destination, 'xb');
		if ($source === false || $output === false) {
			if (is_resource($source)) {
				fclose($source);
			}
			if (is_resource($output)) {
				fclose($output);
			}
			throw new RuntimeException('Unable to stage selected file');
		}

		try {
			while (!feof($source)) {
				$chunk = fread($source, 1048576);
				if ($chunk === false) {
					throw new RuntimeException('Unable to read selected file');
				}
				if ($chunk === '') {
					if (feof($source)) {
						break;
					}
					throw new RuntimeException('Selected file stream stalled');
				}

				$length = strlen($chunk);
				if ($maxSize !== -1 && $length > $maxSize - $stagedBytes) {
					throw new ArchiveLimitExceededException('Selected files exceed the configured compression limit');
				}
				$free = @disk_free_space($workDir);
				if ($free !== false && $length + self::TEMP_DISK_RESERVE_BYTES > $free) {
					throw new ArchiveLimitExceededException('Temporary disk space reserve would be exhausted');
				}
				$this->writeAll($output, $chunk);
				$stagedBytes += $length;
			}
		} finally {
			fclose($source);
			fclose($output);
		}
	}

	/**
	 * @param list<string> $topLevelNames
	 * @return list<array{path: string, suffix: string}>
	 */
	private function createArchive(string $workDir, string $stageDir, string $targetName, array $topLevelNames, ArchiveFormat $format, ?int $volumeSize): array {
		$outputPath = rtrim($workDir, '/') . '/output' . $format->extension();
		if (file_exists($outputPath)) {
			throw new RuntimeException('Temporary archive output already exists');
		}

		if ($format === ArchiveFormat::TAR_GZ) {
			$tarName = preg_replace('/\.gz$/i', '', $targetName);
			if (!is_string($tarName) || $tarName === '' || $tarName === $targetName) {
				throw new RuntimeException('Invalid TAR.GZ target name');
			}
			$tarPath = rtrim($workDir, '/') . '/' . $this->safeLocalName($tarName);
			$this->sevenZip->run([
				'a', '-ttar', '-bd', '-bb0', '-y', '-spd', '--', $tarPath, ...$topLevelNames,
			], $stageDir);

			$gzipArguments = ['a', '-tgzip', '-bd', '-bb0', '-y', '-spd'];
			if ($volumeSize !== null) {
				$gzipArguments[] = '-v' . $volumeSize . 'b';
			}
			$gzipArguments[] = '--';
			$gzipArguments[] = $outputPath;
			$gzipArguments[] = $tarPath;
			$this->sevenZip->run($gzipArguments, $workDir);
		} else {
			$type = match ($format) {
				ArchiveFormat::ZIP => 'zip',
				ArchiveFormat::TAR => 'tar',
				ArchiveFormat::SEVEN_ZIP => '7z',
				default => throw new UnsupportedArchiveFormatException('Unsupported archive format for 7-Zip compression'),
			};
			$arguments = ['a', '-t' . $type, '-bd', '-bb0', '-y', '-spd'];
			if ($volumeSize !== null) {
				$arguments[] = '-v' . $volumeSize . 'b';
			}
			$arguments[] = '--';
			$arguments[] = $outputPath;
			array_push($arguments, ...$topLevelNames);
			$this->sevenZip->run($arguments, $stageDir);
		}

		if ($volumeSize !== null) {
			return $this->collectVolumePaths($outputPath);
		}
		if (!is_file($outputPath) || filesize($outputPath) === false) {
			throw new RuntimeException('7-Zip did not create the expected archive');
		}
		return [['path' => $outputPath, 'suffix' => '']];
	}

	/** @return list<array{path: string, suffix: string}> */
	private function collectVolumePaths(string $outputPath): array {
		if (is_file($outputPath)) {
			throw new RuntimeException('7-Zip created an unexpected non-volume archive');
		}
		$matches = glob($outputPath . '.*', GLOB_NOSORT);
		if ($matches === false || $matches === []) {
			throw new RuntimeException('7-Zip did not create multipart archive volumes');
		}

		$parts = [];
		foreach ($matches as $path) {
			if (!is_file($path)) {
				continue;
			}
			$suffix = substr($path, strlen($outputPath));
			if (preg_match('/^\.(\d{3,})$/D', $suffix, $match) !== 1) {
				continue;
			}
			$index = (int)$match[1];
			if ($index < 1 || isset($parts[$index])) {
				throw new RuntimeException('7-Zip created ambiguous multipart archive volumes');
			}
			$size = filesize($path);
			if ($size === false || $size <= 0) {
				throw new RuntimeException('7-Zip created an invalid multipart archive volume');
			}
			$parts[$index] = ['path' => $path, 'suffix' => $suffix];
		}

		if ($parts === []) {
			throw new RuntimeException('7-Zip did not create recognized multipart archive volumes');
		}
		if (count($parts) > self::MAX_VOLUME_COUNT) {
			throw new ArchiveLimitExceededException('Multipart archive exceeds the 1000 part limit');
		}
		ksort($parts, SORT_NUMERIC);
		$expected = 1;
		foreach (array_keys($parts) as $index) {
			if ($index !== $expected) {
				throw new RuntimeException('Multipart archive volume sequence is incomplete');
			}
			$expected++;
		}

		return array_values($parts);
	}

	/** @param list<array{path: string, suffix: string}> $archiveParts */
	private function copyArchivesToNextcloud(array $archiveParts, Folder $targetParent, string $targetName): File {
		if ($archiveParts === []) {
			throw new RuntimeException('No archive output was produced');
		}

		$totalSize = 0;
		$targets = [];
		foreach ($archiveParts as $part) {
			$size = filesize($part['path']);
			if ($size === false) {
				throw new RuntimeException('Unable to determine archive size');
			}
			if ($size > PHP_INT_MAX - $totalSize) {
				throw new ArchiveLimitExceededException('Archive output exceeds the supported integer range');
			}
			$totalSize += $size;
			$name = $targetName . $part['suffix'];
			$targetParent->verifyPath($name);
			if ($targetParent->nodeExists($name)) {
				throw new TargetAlreadyExists();
			}
			$targets[] = ['path' => $part['path'], 'name' => $name, 'size' => $size];
		}

		$free = $targetParent->getFreeSpace();
		if ($free >= 0 && $totalSize > $free) {
			throw new ArchiveLimitExceededException('Archive does not fit in the destination quota');
		}

		$created = [];
		try {
			foreach ($targets as $target) {
				$file = $targetParent->newFile($target['name']);
				$created[] = $file;
				$this->copyLocalArchiveToNode($target['path'], $file, $target['size']);
			}
		} catch (Throwable $e) {
			foreach ($created as $file) {
				try {
					$file->delete();
				} catch (Throwable) {
				}
			}
			throw $e;
		}

		return $created[0];
	}

	private function copyLocalArchiveToNode(string $archivePath, File $file, int $size): void {
		$source = @fopen($archivePath, 'rb');
		$output = $file->fopen('wb');
		if ($source === false || $output === false) {
			if (is_resource($source)) {
				fclose($source);
			}
			if (is_resource($output)) {
				fclose($output);
			}
			throw new RuntimeException('Unable to write archive to Nextcloud storage');
		}

		try {
			$copied = stream_copy_to_stream($source, $output);
			if ($copied === false || $copied !== $size) {
				throw new RuntimeException('Archive copy ended before all bytes were written');
			}
		} finally {
			fclose($source);
			fclose($output);
		}
	}

	/** @param resource $stream */
	private function writeAll($stream, string $data): void {
		$offset = 0;
		$length = strlen($data);
		while ($offset < $length) {
			$written = fwrite($stream, substr($data, $offset));
			if ($written === false || $written === 0) {
				throw new RuntimeException('Unable to write temporary staged file');
			}
			$offset += $written;
		}
	}
}
