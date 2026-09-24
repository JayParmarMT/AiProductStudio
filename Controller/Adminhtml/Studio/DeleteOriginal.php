<?php
namespace Meetanshi\AiProductStudio\Controller\Adminhtml\Studio;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Gallery\Processor as GalleryProcessor;
use Magento\Catalog\Model\Product\Gallery\CreateHandler as GalleryCreateHandler;
use Psr\Log\LoggerInterface;

/**
 * Removes the original image from a product's media gallery after it has
 * been cropped/resized into the SKU-scoped wysiwyg folder (Step 2, part 5).
 *
 * If the removed image was assigned as the base/small/thumbnail image, that
 * role is reassigned to another remaining gallery image instead of being
 * left as "no_selection", so the product is never left without a base image.
 */
class DeleteOriginal extends Action
{
    const ADMIN_RESOURCE = 'Meetanshi_AiProductStudio::config';

    private const ROLE_ATTRIBUTES = ['image', 'small_image', 'thumbnail'];

    protected $resultJsonFactory;
    protected $productRepository;
    protected $galleryProcessor;
    protected $galleryCreateHandler;
    protected $logger;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        ProductRepositoryInterface $productRepository,
        GalleryProcessor $galleryProcessor,
        GalleryCreateHandler $galleryCreateHandler,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->productRepository = $productRepository;
        $this->galleryProcessor = $galleryProcessor;
        $this->galleryCreateHandler = $galleryCreateHandler;
        $this->logger = $logger;
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        $productId = $this->getRequest()->getParam('product_id');
        $file = $this->getRequest()->getParam('image_path');

        if (!$productId || !$file) {
            return $result->setData(['success' => false, 'message' => 'Missing required parameters.']);
        }

        try {
            $product = $this->productRepository->getById($productId);

            $entries = $product->getMediaGalleryEntries() ?: [];
            $found = false;
            $remaining = [];
            foreach ($entries as $entry) {
                if ($entry->getFile() === $file) {
                    $found = true;
                    continue;
                }
                $remaining[] = $entry;
            }

            if (!$found) {
                return $result->setData(['success' => false, 'message' => 'Image not found in product gallery.']);
            }

            usort($remaining, function ($a, $b) {
                return $a->getPosition() <=> $b->getPosition();
            });
            $replacementFile = isset($remaining[0]) ? $remaining[0]->getFile() : null;

            $vacatedRoles = [];
            foreach (self::ROLE_ATTRIBUTES as $attrCode) {
                if ($product->getData($attrCode) === $file) {
                    $vacatedRoles[] = $attrCode;
                }
            }

            $this->galleryProcessor->removeImage($product, $file);

            // Reassign vacated roles before the create handler runs, so it persists
            // the replacement instead of falling back to "no_selection".
            foreach ($vacatedRoles as $attrCode) {
                $product->setData($attrCode, $replacementFile ?: 'no_selection');
            }

            $this->galleryCreateHandler->execute($product);

            return $result->setData([
                'success' => true,
                'message' => 'Original image removed from product gallery.',
                'reassigned_roles' => $vacatedRoles,
                'reassigned_to' => $replacementFile,
                'no_replacement_available' => empty($remaining) && !empty($vacatedRoles),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('AiProductStudio DeleteOriginal Error: ' . $e->getMessage());
            return $result->setData(['success' => false, 'message' => 'Error deleting image: ' . $e->getMessage()]);
        }
    }

    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed(self::ADMIN_RESOURCE);
    }
}
