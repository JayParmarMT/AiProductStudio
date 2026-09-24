<?php
namespace Meetanshi\AiProductStudio\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Framework\Registry;

/**
 * Class Studio
 *
 * Admin block for AI Product Studio page.
 */
class Studio extends Template
{
    /**
     * @var Registry
     */
    protected $registry;

    /**
     * Constructor
     *
     * @param Template\Context $context
     * @param Registry $registry
     * @param array $data
     */
    public function __construct(
        Template\Context $context,
        Registry $registry,
        array $data = []
    ) {
        $this->registry = $registry;
        parent::__construct($context, $data);
    }

    /**
     * Get the current product from the registry.
     *
     * @return \Magento\Catalog\Model\Product|null
     */
    public function getProduct()
    {
        return $this->registry->registry('current_product');
    }

    /**
     * Get product media gallery images.
     *
     * @return \Magento\Framework\Data\Collection|array
     */
    public function getProductImages()
    {
        $product = $this->getProduct();
        if ($product) {
            return $product->getMediaGalleryImages();
        }
        return [];
    }

    /**
     * Get media base URL for displaying generated images.
     *
     * @return string
     */
    public function getMediaUrl()
    {
        return $this->_storeManager->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA);
    }

    /**
     * Get admin config value.
     *
     * @param string $path
     * @return string|null
     */
    public function getConfig($path)
    {
        return $this->_scopeConfig->getValue($path);
    }
}
