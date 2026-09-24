<?php
namespace Meetanshi\AiProductStudio\Model\Service;

use Magento\Framework\Filesystem\Driver\File as FileDriver;

/**
 * Bulk-compression engine for Step 3 (pure GD, no AI/API calls).
 * Keeps original dimensions/proportions, no cropping. JPEGs are compressed
 * by stepping quality down until the target size is hit; PNGs use maximum
 * lossless compression only (PNG has no quality scale), so they may stay
 * flagged above target if the source is a large photographic PNG.
 */
class ImageCompressorService
{
    public const DEFAULT_TARGET_BYTES = 307200; // 300KB
    public const MIN_QUALITY = 30;
    public const MAX_QUALITY = 95;
    public const QUALITY_STEP = 5;

    protected $fileDriver;

    public function __construct(FileDriver $fileDriver)
    {
        $this->fileDriver = $fileDriver;
    }

    /**
     * Copies the source file to the backup path, but only if no backup
     * exists there yet — protects the very first (true) original from
     * being overwritten by a backup of an already-compressed file on a
     * second run.
     */
    public function ensureBackup(string $sourceAbsolutePath, string $backupAbsolutePath): bool
    {
        if ($this->fileDriver->isExists($backupAbsolutePath)) {
            return false;
        }

        $backupDir = dirname($backupAbsolutePath);
        if (!$this->fileDriver->isDirectory($backupDir)) {
            $this->fileDriver->createDirectory($backupDir, 0777);
        }

        $this->fileDriver->copy($sourceAbsolutePath, $backupAbsolutePath);
        return true;
    }

    /**
     * Compresses the image in place.
     *
     * @param string $absolutePath
     * @param int|null $exactQuality Manual mode: apply this quality directly (skips the target-size loop)
     * @param int $targetBytes
     * @param int $minQuality
     * @return array
     */
    public function compressToTarget(
        string $absolutePath,
        ?int $exactQuality = null,
        int $targetBytes = self::DEFAULT_TARGET_BYTES,
        int $minQuality = self::MIN_QUALITY
    ): array {
        $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
        $originalSize = filesize($absolutePath);
        $sizeInfo = getimagesize($absolutePath);

        if (!$sizeInfo) {
            return ['success' => false, 'error' => 'Failed to read image dimensions.'];
        }

        [$width, $height] = $sizeInfo;

        // Already under target and not an explicit manual request — leave it alone.
        if ($exactQuality === null && $originalSize <= $targetBytes) {
            return [
                'success' => true,
                'format' => $extension,
                'original_size' => $originalSize,
                'final_size' => $originalSize,
                'quality' => null,
                'width' => $width,
                'height' => $height,
                'flagged' => false,
                'skipped' => true,
            ];
        }

        if (in_array($extension, ['jpg', 'jpeg'], true)) {
            return $this->compressJpeg($absolutePath, $originalSize, $width, $height, $exactQuality, $targetBytes, $minQuality);
        }

        if ($extension === 'png') {
            return $this->compressPng($absolutePath, $originalSize, $width, $height, $exactQuality, $targetBytes);
        }

        return ['success' => false, 'error' => 'Unsupported format: ' . $extension];
    }

    protected function compressJpeg(
        string $absolutePath,
        int $originalSize,
        int $width,
        int $height,
        ?int $exactQuality,
        int $targetBytes,
        int $minQuality
    ): array {
        $image = imagecreatefromjpeg($absolutePath);
        if (!$image) {
            return ['success' => false, 'error' => 'Failed to load JPEG image.'];
        }

        if ($exactQuality !== null) {
            imagejpeg($image, $absolutePath, max(1, min(100, $exactQuality)));
            $finalQuality = $exactQuality;
        } else {
            $finalQuality = self::MIN_QUALITY;
            for ($quality = self::MAX_QUALITY; $quality >= $minQuality; $quality -= self::QUALITY_STEP) {
                imagejpeg($image, $absolutePath, $quality);
                $finalQuality = $quality;
                clearstatcache(true, $absolutePath);
                if (filesize($absolutePath) <= $targetBytes) {
                    break;
                }
            }
        }
        imagedestroy($image);

        clearstatcache(true, $absolutePath);
        $finalSize = filesize($absolutePath);

        return [
            'success' => true,
            'format' => 'jpeg',
            'original_size' => $originalSize,
            'final_size' => $finalSize,
            'quality' => $finalQuality,
            'width' => $width,
            'height' => $height,
            'flagged' => $finalSize > $targetBytes,
        ];
    }

    protected function compressPng(
        string $absolutePath,
        int $originalSize,
        int $width,
        int $height,
        ?int $exactQuality,
        int $targetBytes
    ): array {
        $image = imagecreatefrompng($absolutePath);
        if (!$image) {
            return ['success' => false, 'error' => 'Failed to load PNG image.'];
        }

        $hasAlpha = $this->pngHasTransparency($image, $width, $height);

        // PNG compression is lossless (0-9). Manual quality (0-100) is mapped
        // onto that scale; auto mode always uses maximum compression.
        $level = $exactQuality !== null ? (int) round((100 - $exactQuality) / 100 * 9) : 9;
        $level = max(0, min(9, $level));

        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagepng($image, $absolutePath, $level);
        imagedestroy($image);

        clearstatcache(true, $absolutePath);
        $finalSize = filesize($absolutePath);

        return [
            'success' => true,
            'format' => 'png',
            'original_size' => $originalSize,
            'final_size' => $finalSize,
            'quality' => null,
            'width' => $width,
            'height' => $height,
            'flagged' => $finalSize > $targetBytes,
            'has_alpha' => $hasAlpha,
        ];
    }

    /**
     * Cheap sampled scan for an alpha channel in use — avoids a full pixel
     * scan on large images while still catching the common transparent-PNG case.
     */
    protected function pngHasTransparency($image, int $width, int $height): bool
    {
        if (imagecolortransparent($image) >= 0) {
            return true;
        }
        if (!imageistruecolor($image) || $width < 1 || $height < 1) {
            return false;
        }

        $step = max(1, (int) (min($width, $height) / 50));
        for ($x = 0; $x < $width; $x += $step) {
            for ($y = 0; $y < $height; $y += $step) {
                $rgba = imagecolorat($image, $x, $y);
                $alpha = ($rgba & 0x7F000000) >> 24;
                if ($alpha > 0) {
                    return true;
                }
            }
        }
        return false;
    }
}
