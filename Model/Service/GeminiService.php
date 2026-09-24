<?php
namespace Meetanshi\AiProductStudio\Model\Service;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use Meetanshi\AiProductStudio\Model\LogFactory;

/**
 * Class GeminiService
 *
 * Service class for Gemini API image generation.
 */
class GeminiService
{
    public const XML_PATH_API_KEY = 'ai_product_studio/general/api_key';
    public const XML_PATH_MODEL = 'ai_product_studio/general/model';
    public const XML_PATH_INSTRUCTIONS = 'ai_product_studio/general/store_owner_instructions';

    public const API_BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/models/';

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var Curl
     */
    protected $curl;

    /**
     * @var Json
     */
    protected $json;

    /**
     * @var DirectoryList
     */
    protected $directoryList;

    /**
     * @var EncryptorInterface
     */
    protected $encryptor;

    /**
     * @var LogFactory
     */
    protected $logFactory;

    /**
     * @var FileDriver
     */
    protected $fileDriver;

    /**
     * Constructor.
     *
     * @param ScopeConfigInterface $scopeConfig
     * @param Curl $curl
     * @param Json $json
     * @param DirectoryList $directoryList
     * @param EncryptorInterface $encryptor
     * @param LogFactory $logFactory
     * @param FileDriver $fileDriver
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        Curl $curl,
        Json $json,
        DirectoryList $directoryList,
        EncryptorInterface $encryptor,
        LogFactory $logFactory,
        FileDriver $fileDriver
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->curl = $curl;
        $this->json = $json;
        $this->directoryList = $directoryList;
        $this->encryptor = $encryptor;
        $this->logFactory = $logFactory;
        $this->fileDriver = $fileDriver;
    }

    /**
     * Detect if model supports image generation.
     *
     * @param string $model
     * @return bool
     */
    protected function isImageModel($model)
    {
        return (stripos($model, 'image') !== false);
    }

    /**
     * Resolve full API URL based on model config.
     *
     * @param string $model
     * @return string
     */
    protected function resolveModelUrl($model)
    {
        $model = trim($model);

        if (preg_match('#^https?://#', $model)) {
            return rtrim($model, ':') . ':generateContent';
        }

        if (preg_match('#^models/#i', $model)) {
            return 'https://generativelanguage.googleapis.com/v1beta/' . $model . ':generateContent';
        }

        return self::API_BASE_URL . $model . ':generateContent';
    }

    /**
     * Main image generation service
     *
     * @param array $params
     * @param array $imagePaths
     * @return array
     */
    public function generateImage($params, $imagePaths = [])
    {
        $apiKey = $this->getApiKey();
        if (!$apiKey) {
            return ['error' => 'API Key not configured'];
        }

        $model = $this->scopeConfig->getValue(self::XML_PATH_MODEL) ?: 'gemini-3.0-flash-image-preview';
        $url = $this->resolveModelUrl($model);
        $isImageModel = $this->isImageModel($model);

        if (!$isImageModel) {
            return [
                'error' => "Selected model '{$model}' cannot generate images. 
Use an image model such as:
- gemini-2.5-flash-image
- gemini-3.0-pro-image-preview
- gemini-3.0-flash-image-preview"
            ];
        }

        $systemPrompt = $this->buildSystemPrompt($params);
        $payload = $this->buildPayload($params, $imagePaths, $systemPrompt, $isImageModel);

        $this->curl->addHeader('Content-Type', 'application/json');
        $this->curl->addHeader('x-goog-api-key', $apiKey);

        $this->curl->post($url, $this->json->serialize($payload));

        $response = $this->curl->getBody();
        $responseData = $this->json->unserialize($response);

        $this->logUsage($model, $params, $responseData);

        return $this->processImageResponse($responseData, $isImageModel);
    }

    /**
     * Get decrypted API key.
     *
     * @return string|null
     */
    protected function getApiKey()
    {
        $apiKey = $this->scopeConfig->getValue(self::XML_PATH_API_KEY);
        return $apiKey ? $this->encryptor->decrypt($apiKey) : null;
    }

    /**
     * Builds unified system prompt.
     *
     * @param array $params
     * @return string
     */
    protected function buildSystemPrompt($params)
    {
        $storeInstructions = $this->scopeConfig->getValue(self::XML_PATH_INSTRUCTIONS) ?: '';

        $prompt = "SYSTEM: You are the Magento Product Studio Engine.\n";
        $prompt .= "Your job is to generate and edit product images with:\n";
        $prompt .= "- Accurate colors\n- Clean edges\n- No distortions\n- No unwanted text or logos\n";
        $prompt .= "- Consistent style across catalog\n\n";

        if ($storeInstructions) {
            $prompt .= "Store instructions:\n" . $storeInstructions . "\n\n";
        }

        $prompt .= "User request: " . ($params['prompt'] ?? '') . "\n";

        if (!empty($params['style'])) {
            $prompt .= "Style: " . $params['style'] . "\n";
        }
        if (!empty($params['background_type'])) {
            $prompt .= "Background: " . $params['background_type'] . "\n";
        }
        if (!empty($params['operation'])) {
            $prompt .= "Operation: " . $params['operation'] . "\n";
        }

        return $prompt;
    }

    /**
     * Build payload for Gemini 2.5/3.0 API
     *
     * @param array $params
     * @param array $imagePaths
     * @param string $systemPrompt
     * @param bool $isImageModel
     * @return array
     */
    protected function buildPayload($params, $imagePaths, $systemPrompt, $isImageModel)
    {
        $model = $this->scopeConfig->getValue(self::XML_PATH_MODEL) ?: '';
        $isGemini25FlashImage = stripos($model, '2.5-flash-image') !== false;

        if ($isGemini25FlashImage) {
            $parts = [];

            $userPrompt = $params['prompt'] ?? '';
            $parts[] = ['text' => $userPrompt];

            foreach ($imagePaths as $imagePath) {
                if ($this->fileDriver->isExists($imagePath)) {
                    $imageBase64 = base64_encode($this->fileDriver->fileGetContents($imagePath));
                    $parts[] = [
                        "inline_data" => [
                            "mime_type" => mime_content_type($imagePath),
                            "data" => $imageBase64
                        ]
                    ];
                }
            }

            return [
                'contents' => [
                    [
                        'parts' => $parts
                    ]
                ]
            ];
        }

        $parts = [];
        $parts[] = ['text' => $systemPrompt];

        foreach ($imagePaths as $imagePath) {
            if ($this->fileDriver->isExists($imagePath)) {
                $imageBase64 = base64_encode($this->fileDriver->fileGetContents($imagePath));
                $parts[] = [
                    "inline_data" => [
                        "mime_type" => mime_content_type($imagePath),
                        "data" => $imageBase64
                    ]
                ];
            }
        }

        if ($isImageModel) {
            $config = [
                'responseModalities' => ['IMAGE'],
                'imageConfig' => ['imageSize' => '2K']
            ];
        } else {
            $config = [
                'responseModalities' => ['TEXT']
            ];
        }

        return [
            'contents' => [
                ['parts' => $parts]
            ],
            'generationConfig' => $config
        ];
    }

    /**
     * Saves API usage logs
     *
     * @param string $model
     * @param array $params
     * @param array $responseData
     * @return void
     */
    protected function logUsage($model, $params, $responseData)
    {
        try {
            $log = $this->logFactory->create();
            $log->setModel($model);
            $log->setRequestType($params['operation'] ?? 'generate');

            if (isset($responseData['usageMetadata'])) {
                $log->setPromptTokens($responseData['usageMetadata']['promptTokenCount'] ?? 0);
                $log->setCompletionTokens($responseData['usageMetadata']['candidatesTokenCount'] ?? 0);
                $log->setTotalTokens($responseData['usageMetadata']['totalTokenCount'] ?? 0);
            }

            if (isset($responseData['error'])) {
                $log->setStatus('error');
                $log->setErrorMessage(json_encode($responseData['error']));
            } else {
                $log->setStatus('success');
            }

            $log->save();
        } catch (\Exception $e) {
            unset($e);
        }
    }

    /**
     * Handle image-producing responses
     *
     * @param array $responseData
     * @param bool $isImageModel
     * @return array
     */
    protected function processImageResponse($responseData, $isImageModel)
    {
        if (!$isImageModel) {
            return [
                'error' => 'Model does not support images.',
                'response' => $responseData
            ];
        }

        $base64 = null;

        if (isset($responseData['candidates'][0]['content']['parts'])) {
            foreach ($responseData['candidates'][0]['content']['parts'] as $part) {
                if (isset($part['inlineData']['data'])) {
                    $base64 = $part['inlineData']['data'];
                    break;
                }
                if (isset($part['inline_data']['data'])) {
                    $base64 = $part['inline_data']['data'];
                    break;
                }
                if (isset($part['data'])) {
                    $base64 = $part['data'];
                    break;
                }
            }
        }

        if (!$base64) {
            return ['error' => 'Failed to generate image.', 'raw' => $responseData];
        }

        $mediaDir = $this->directoryList->getPath(DirectoryList::MEDIA);
        $fileName = 'ai_studio/generated_' . time() . '.png';
        $filePath = $mediaDir . '/' . $fileName;

        $parentDir = $this->fileDriver->getParentDirectory($filePath);
        if (!$this->fileDriver->isDirectory($parentDir)) {
            $this->fileDriver->createDirectory($parentDir, 0777);
        }

        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $this->fileDriver->filePutContents($filePath, base64_decode($base64));

        return [
            'success' => true,
            'message' => 'Image generated successfully.',
            'path' => $fileName
        ];
    }
}
