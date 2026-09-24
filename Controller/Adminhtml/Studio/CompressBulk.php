<?php
namespace Meetanshi\AiProductStudio\Controller\Adminhtml\Studio;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Meetanshi\AiProductStudio\Model\Service\ImageCompressorService;
use Psr\Log\LoggerInterface;

/**
 * Compresses every gallery image of the current product to the Step 3
 * target (<=300KB), backing up each original first. Scoped to one product
 * per run — a store-wide sweep across the whole catalog would need a
 * separate CLI/queue-based command, which is out of scope here.
 */
class CompressBulk extends Action
{
    const ADMIN_RESOURCE = 'Meetanshi_AiProductStudio::config';

    protected $resultJsonFactory;
    protected $directoryList;
    protected $fileDriver;
    protected $compressor;
    protected $productRepository;
    protected $logger;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        DirectoryList $directoryList,
        FileDriver $fileDriver,
        ImageCompressorService $compressor,
        ProductRepositoryInterface $productRepository,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->directoryList = $directoryList;
        $this->fileDriver = $fileDriver;
        $this->compressor = $compressor;
        $this->productRepository = $productRepository;
        $this->logger = $logger;
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        $productId = $this->getRequest()->getParam('product_id');

        if (!$productId) {
            return $result->setData(['error' => 'Missing product id.']);
        }

        try {
            $product = $this->productRepository->getById($productId);
            $entries = $product->getMediaGalleryEntries() ?: [];

            $mediaDir = $this->directoryList->getPath(DirectoryList::MEDIA);
            $images = [];

            foreach ($entries as $entry) {
                $file = $entry->getFile();
                if (!$file) {
                    continue;
                }

                $relativeCatalogPath = 'catalog/product' . $file;
                $absolutePath = $mediaDir . '/' . $relativeCatalogPath;

                if (!$this->fileDriver->isExists($absolutePath)) {
                    $images[] = ['path' => $file, 'filename' => basename($file), 'error' => 'File not found on disk.'];
                    continue;
                }

                $backupPath = $mediaDir . '/ai_studio/backup/' . $relativeCatalogPath;
                $this->compressor->ensureBackup($absolutePath, $backupPath);

                $data = $this->compressor->compressToTarget($absolutePath);

                if (empty($data['success'])) {
                    $images[] = [
                        'path' => $file,
                        'filename' => basename($file),
                        'error' => $data['error'] ?? 'Compression failed.',
                    ];
                    continue;
                }

                $images[] = array_merge($data, [
                    'path' => $file,
                    'filename' => basename($file),
                ]);
            }

            return $result->setData([
                'success' => true,
                'message' => count($images) . ' image(s) processed.',
                'images' => $images,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('AiProductStudio CompressBulk Error: ' . $e->getMessage());
            return $result->setData(['error' => $e->getMessage()]);
        }
    }

    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed(self::ADMIN_RESOURCE);
    }
}
