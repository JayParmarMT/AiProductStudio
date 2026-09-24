<?php
namespace Meetanshi\AiProductStudio\Controller\Adminhtml\Studio;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Api\ImageContentFactory;
use Magento\Framework\Api\Data\ImageContentInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Class Save
 *
 * Admin controller to save AI-generated images to product gallery.
 */
class Save extends Action
{
    /**
     * @var JsonFactory
     */
    protected $resultJsonFactory;

    /**
     * @var ProductRepositoryInterface
     */
    protected $productRepository;

    /**
     * @var Filesystem
     */
    protected $filesystem;

    /**
     * @var ImageContentFactory
     */
    protected $imageContentFactory;

    /**
     * @var \Magento\Framework\Filesystem\Directory\WriteInterface
     */
    protected $mediaDirectory;

    /**
     * Constructor
     *
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param ProductRepositoryInterface $productRepository
     * @param Filesystem $filesystem
     * @param ImageContentFactory $imageContentFactory
     */
    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        ProductRepositoryInterface $productRepository,
        Filesystem $filesystem,
        ImageContentFactory $imageContentFactory
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->productRepository = $productRepository;
        $this->filesystem = $filesystem;
        $this->imageContentFactory = $imageContentFactory;
        $this->mediaDirectory = $filesystem->getDirectoryWrite(DirectoryList::MEDIA);
    }

    /**
     * Execute action to save generated image to product gallery.
     *
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        $params = $this->getRequest()->getParams();

        if (!isset($params['image_path']) || !isset($params['product_id'])) {
            return $result->setData(['success' => false, 'message' => 'Missing required parameters.']);
        }

        try {
            $productId = $params['product_id'];
            $relativePath = $params['image_path'];

            if (strpos($relativePath, '..') !== false) {
                throw new LocalizedException(__('Invalid image path.'));
            }

            $absolutePath = $this->mediaDirectory->getAbsolutePath($relativePath);

            if (!$this->mediaDirectory->isFile($relativePath)) {
                return $result->setData(['success' => false, 'message' => 'Image file not found: ' . $relativePath]);
            }

            $product = $this->productRepository->getById($productId);

            $product->addImageToMediaGallery(
                $absolutePath,
                ['image', 'small_image', 'thumbnail'],
                false,
                false
            );

            $this->productRepository->save($product);

            return $result->setData(['success' => true, 'message' => 'Image saved to product gallery.']);

        } catch (\Exception $e) {
            return $result->setData(['success' => false, 'message' => 'Error saving image: ' . $e->getMessage()]);
        }
    }

    /**
     * Check admin permissions for this controller.
     *
     * @return bool
     */
    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed('Meetanshi_AiProductStudio::config');
    }
}
