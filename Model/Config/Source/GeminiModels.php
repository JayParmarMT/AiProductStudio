<?php
namespace Meetanshi\AiProductStudio\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Class GeminiModels
 *
 * Provides Gemini model options for AI Product Studio configuration.
 */
class GeminiModels implements OptionSourceInterface
{
    /**
     * Get available Gemini model options.
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['value' => 'gemini-3-pro-image-preview', 'label' => __('Gemini 3 Pro Image Preview (Recommended)')],
            ['value' => 'gemini-2.5-flash-image', 'label' => __('Gemini 2.5 Flash Image (Fast/Cheap)')]
        ];
    }
}
