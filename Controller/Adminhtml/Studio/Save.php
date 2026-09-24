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
use Magento\Catalog\Model\Product\Gallery\Processor as GalleryProcessor;
use Magento\Catalog\Model\Product\Gallery\CreateHandler as GalleryCreateHandler;

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
     * @var GalleryProcessor
     */
    protected $galleryProcessor;

    /**
     * @var GalleryCreateHandler
     */
    protected $galleryCreateHandler;

    /**
     * Constructor
     *
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param ProductRepositoryInterface $productRepository
     * @param Filesystem $filesystem
     * @param ImageContentFactory $imageContentFactory
     * @param GalleryProcessor $galleryProcessor
     * @param GalleryCreateHandler $galleryCreateHandler
     */
    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        ProductRepositoryInterface $productRepository,
        Filesystem $filesystem,
        ImageContentFactory $imageContentFactory,
        GalleryProcessor $galleryProcessor,
        GalleryCreateHandler $galleryCreateHandler
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->productRepository = $productRepository;
        $this->filesystem = $filesystem;
        $this->imageContentFactory = $imageContentFactory;
        $this->mediaDirectory = $filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        $this->galleryProcessor = $galleryProcessor;
        $this->galleryCreateHandler = $galleryCreateHandler;
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

            // Add the image to the product's media gallery (populates the
            // media_gallery data on the product object, copying it to the tmp dir).
            $this->galleryProcessor->addImage(
                $product,
                $absolutePath,
                ['image', 'small_image', 'thumbnail'],
                false,
                false
            );

            // Persist only the media gallery via the gallery CreateHandler. This
            // writes the media gallery tables directly and moves the file into
            // place, without running full product validation (which would fail on
            // required custom attributes such as trip_price).
            $this->galleryCreateHandler->execute($product);

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
