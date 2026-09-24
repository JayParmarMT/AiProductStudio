<?php
namespace Meetanshi\AiProductStudio\Controller\Adminhtml\Studio;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use Psr\Log\LoggerInterface;

/**
 * Controller for center-crop and resize of product images.
 * No AI API used — pure PHP GD/Imagick processing.
 */
class CropResize extends Action
{
    const ADMIN_RESOURCE = 'Meetanshi_AiProductStudio::studio';

    /**
     * Predefined crop dimensions
     */
    const DIMENSIONS = [
        '2560x1440' => ['width' => 2560, 'height' => 1440],
        '2560x1120' => ['width' => 2560, 'height' => 1120],
        '800x800'   => ['width' => 800,  'height' => 800],
        '720x540'   => ['width' => 720,  'height' => 540],
        '500x330'   => ['width' => 500,  'height' => 330],
    ];

    protected $resultJsonFactory;
    protected $directoryList;
    protected $fileDriver;
    protected $logger;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        DirectoryList $directoryList,
        FileDriver $fileDriver,
        LoggerInterface $logger
    ) {
        $this->resultJsonFactory = $resultJsonFactory;
        $this->directoryList = $directoryList;
        $this->fileDriver = $fileDriver;
        $this->logger = $logger;
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();

        try {
            $imagePath = $this->getRequest()->getParam('image_path');
            $selectedSizes = $this->getRequest()->getParam('sizes');
            $quality = (int)($this->getRequest()->getParam('quality') ?: 85);

            if (!$imagePath) {
                return $result->setData(['error' => 'No image selected.']);
            }

            if (empty($selectedSizes)) {
                $selectedSizes = array_keys(self::DIMENSIONS);
            } elseif (is_string($selectedSizes)) {
                $selectedSizes = explode(',', $selectedSizes);
            }

            // Resolve full path
            $mediaDir = $this->directoryList->getPath(DirectoryList::MEDIA);
            $catalogDir = $mediaDir . '/catalog/product';
            $fullPath = $catalogDir . $imagePath;

            if (!$this->fileDriver->isExists($fullPath)) {
                return $result->setData(['error' => 'Image file not found: ' . $imagePath]);
            }

            // Get original filename without extension
            $pathInfo = pathinfo($fullPath);
            $originalName = $pathInfo['filename'];
            $extension = strtolower($pathInfo['extension']);

            // Validate format
            if (!in_array($extension, ['jpg', 'jpeg', 'png'])) {
                return $result->setData(['error' => 'Unsupported format. Use .jpg, .jpeg, or .png']);
            }

            // Create output directory
            $outputDir = $mediaDir . '/ai_studio/cropped';
            if (!$this->fileDriver->isDirectory($outputDir)) {
                $this->fileDriver->createDirectory($outputDir, 0777);
            }

            // Load source image
            $sourceImage = $this->loadImage($fullPath, $extension);
            if (!$sourceImage) {
                return $result->setData(['error' => 'Failed to load image. Check GD/Imagick support.']);
            }

            $srcWidth = imagesx($sourceImage);
            $srcHeight = imagesy($sourceImage);

            $generatedImages = [];

            foreach ($selectedSizes as $sizeKey) {
                $sizeKey = trim($sizeKey);
                if (!isset(self::DIMENSIONS[$sizeKey])) {
                    continue;
                }

                $targetWidth = self::DIMENSIONS[$sizeKey]['width'];
                $targetHeight = self::DIMENSIONS[$sizeKey]['height'];

                // Center crop and resize
                $croppedImage = $this->centerCropResize(
                    $sourceImage,
                    $srcWidth,
                    $srcHeight,
                    $targetWidth,
                    $targetHeight
                );

                // Generate output filename: originalname-WIDTHxHEIGHT.jpg
                $outputFilename = $originalName . '-' . $sizeKey . '.jpg';
                $outputPath = $outputDir . '/' . $outputFilename;

                // Save as JPEG (web-optimized)
                imagejpeg($croppedImage, $outputPath, $quality);
                imagedestroy($croppedImage);

                $relativePath = 'ai_studio/cropped/' . $outputFilename;
                $generatedImages[] = [
                    'size' => $sizeKey,
                    'width' => $targetWidth,
                    'height' => $targetHeight,
                    'path' => $relativePath,
                    'filename' => $outputFilename,
                    'filesize' => $this->formatFileSize(filesize($outputPath)),
                ];
            }

            imagedestroy($sourceImage);

            return $result->setData([
                'success' => true,
                'message' => count($generatedImages) . ' images generated successfully.',
                'images' => $generatedImages,
                'original' => [
                    'name' => $originalName,
                    'width' => $srcWidth,
                    'height' => $srcHeight,
                ],
            ]);
        } catch (\Exception $e) {
            $this->logger->error('AiProductStudio CropResize Error: ' . $e->getMessage());
            return $result->setData(['error' => $e->getMessage()]);
        }
    }

    /**
     * Center crop and resize image to target dimensions
     */
    protected function centerCropResize($sourceImage, $srcWidth, $srcHeight, $targetWidth, $targetHeight)
    {
        $targetRatio = $targetWidth / $targetHeight;
        $srcRatio = $srcWidth / $srcHeight;

        if ($srcRatio > $targetRatio) {
            // Source is wider — crop left/right
            $cropHeight = $srcHeight;
            $cropWidth = (int)($srcHeight * $targetRatio);
            $cropX = (int)(($srcWidth - $cropWidth) / 2);
            $cropY = 0;
        } else {
            // Source is taller — crop top/bottom
            $cropWidth = $srcWidth;
            $cropHeight = (int)($srcWidth / $targetRatio);
            $cropX = 0;
            $cropY = (int)(($srcHeight - $cropHeight) / 2);
        }

        // Create target canvas
        $target = imagecreatetruecolor($targetWidth, $targetHeight);

        // Preserve quality with resampling
        imagecopyresampled(
            $target,
            $sourceImage,
            0, 0,           // dest x, y
            $cropX, $cropY, // src x, y (center crop offset)
            $targetWidth, $targetHeight, // dest width, height
            $cropWidth, $cropHeight      // src crop width, height
        );

        return $target;
    }

    /**
     * Load image based on format
     */
    protected function loadImage($path, $extension)
    {
        switch ($extension) {
            case 'jpg':
            case 'jpeg':
                return imagecreatefromjpeg($path);
            case 'png':
                return imagecreatefrompng($path);
            default:
                return null;
        }
    }

    /**
     * Format file size for display
     */
    protected function formatFileSize($bytes)
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1) . ' MB';
        }
        return number_format($bytes / 1024, 0) . ' KB';
    }
}
