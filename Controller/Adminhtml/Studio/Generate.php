<?php
namespace Meetanshi\AiProductStudio\Controller\Adminhtml\Studio;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Meetanshi\AiProductStudio\Model\Service\GeminiService;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Class Generate
 *
 * Admin controller for generating AI product image.
 */
class Generate extends Action
{
    /**
     * @var JsonFactory
     */
    protected $resultJsonFactory;

    /**
     * @var GeminiService
     */
    protected $geminiService;

    /**
     * @var Filesystem
     */
    protected $filesystem;

    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var FileDriver
     */
    protected $fileDriver;

    /**
     * Constructor.
     *
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param GeminiService $geminiService
     * @param Filesystem $filesystem
     * @param StoreManagerInterface $storeManager
     * @param FileDriver $fileDriver
     */
    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        GeminiService $geminiService,
        Filesystem $filesystem,
        StoreManagerInterface $storeManager,
        FileDriver $fileDriver
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->geminiService = $geminiService;
        $this->filesystem = $filesystem;
        $this->storeManager = $storeManager;
        $this->fileDriver = $fileDriver;
    }

    /**
     * Execute action to generate AI product image.
     *
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $this->_session->writeClose();

        $result = $this->resultJsonFactory->create();
        $params = $this->getRequest()->getParams();
        
        try {
            $imagePaths = [];
            $editFile = $this->getRequest()->getFiles('edit_image');
            if ($editFile && isset($editFile['tmp_name']) && !empty($editFile['tmp_name'])) {
                $imagePaths[] = $editFile['tmp_name'];
            }
            if (empty($imagePaths) && isset($params['selected_image_path']) && !empty($params['selected_image_path'])) {
                $mediaDir = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);
                $relativePath = $params['selected_image_path'];
                $relativePath = ltrim($relativePath, '/');
                $absolutePath = $mediaDir->getAbsolutePath($relativePath);
                if (!$this->fileDriver->isExists($absolutePath)) {
                    $absolutePath = $mediaDir->getAbsolutePath('catalog/product/' . $relativePath);
                }
                
                if ($this->fileDriver->isExists($absolutePath)) {
                    $imagePaths[] = $absolutePath;
                }
            }

            $serviceParams = [
                'prompt' => $params['prompt'] ?? '',
                'style' => $params['style'] ?? '',
                'background_type' => $params['background_type'] ?? '',
                'operation' => $params['operation'] ?? 'generate',
                'resolution' => $params['resolution'] ?? '2048'
            ];

            $response = $this->geminiService->generateImage($serviceParams, $imagePaths);
            
            if (isset($response['path'])) {
                $mediaUrl = $this->storeManager->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA);
                $response['url'] = $mediaUrl . $response['path'];
            }

            return $result->setData($response);

        } catch (\Exception $e) {
            return $result->setData(['error' => $e->getMessage()]);
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
