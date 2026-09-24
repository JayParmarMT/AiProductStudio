<?php
namespace Meetanshi\AiProductStudio\Controller\Adminhtml\Studio;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use Meetanshi\AiProductStudio\Model\Service\ImageCompressorService;
use Psr\Log\LoggerInterface;

/**
 * Compresses a single gallery image in place (Step 3). Used both for the
 * per-image entry inside a bulk run's results and for the manual
 * "re-compress with this quality" action on a flagged image.
 */
class Compress extends Action
{
    const ADMIN_RESOURCE = 'Meetanshi_AiProductStudio::config';

    protected $resultJsonFactory;
    protected $directoryList;
    protected $fileDriver;
    protected $compressor;
    protected $logger;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        DirectoryList $directoryList,
        FileDriver $fileDriver,
        ImageCompressorService $compressor,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->directoryList = $directoryList;
        $this->fileDriver = $fileDriver;
        $this->compressor = $compressor;
        $this->logger = $logger;
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        $imagePath = $this->getRequest()->getParam('image_path');
        $quality = $this->getRequest()->getParam('quality');
        $quality = ($quality !== null && $quality !== '') ? (int)$quality : null;

        if (!$imagePath) {
            return $result->setData(['error' => 'No image specified.']);
        }

        try {
            $mediaDir = $this->directoryList->getPath(DirectoryList::MEDIA);
            $relativeCatalogPath = 'catalog/product' . $imagePath;
            $absolutePath = $mediaDir . '/' . $relativeCatalogPath;

            if (!$this->fileDriver->isExists($absolutePath)) {
                return $result->setData(['error' => 'Image file not found: ' . $imagePath]);
            }

            $backupPath = $mediaDir . '/ai_studio/backup/' . $relativeCatalogPath;
            $this->compressor->ensureBackup($absolutePath, $backupPath);

            $data = $this->compressor->compressToTarget($absolutePath, $quality);

            if (empty($data['success'])) {
                return $result->setData(['error' => $data['error'] ?? 'Compression failed.']);
            }

            return $result->setData(array_merge($data, [
                'success' => true,
                'path' => $imagePath,
                'filename' => basename($imagePath),
            ]));
        } catch (\Exception $e) {
            $this->logger->error('AiProductStudio Compress Error: ' . $e->getMessage());
            return $result->setData(['error' => $e->getMessage()]);
        }
    }

    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed(self::ADMIN_RESOURCE);
    }
}
